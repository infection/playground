#syntax=docker/dockerfile:1

# Versions
FROM dunglas/frankenphp:1-php8.5 AS frankenphp_upstream

# Tailwind CSS 3 standalone CLI (symfonycasts/tailwind-bundle runs it, see config/packages/symfonycasts_tailwind.yaml).
# Checksums: https://github.com/tailwindlabs/tailwindcss/releases/download/v3.4.19/sha256sums.txt
FROM scratch AS tailwind_amd64
ADD --checksum=sha256:4af3198c015616ea7d6617974ec3d70d987ecc00c1ca8463b0a30fd65cc7c06e \
	https://github.com/tailwindlabs/tailwindcss/releases/download/v3.4.19/tailwindcss-linux-x64 /tailwindcss

FROM scratch AS tailwind_arm64
ADD --checksum=sha256:e5b2d27694daa80cc52ec29553ba2c6bd43d86bd51a9d633ed24058b9c05a676 \
	https://github.com/tailwindlabs/tailwindcss/releases/download/v3.4.19/tailwindcss-linux-arm64 /tailwindcss

ARG TARGETARCH
# hadolint ignore=DL3006
FROM tailwind_${TARGETARCH} AS tailwind

# The different stages of this Dockerfile are meant to be built into separate images
# https://docs.docker.com/build/building/multi-stage/#stop-at-a-specific-build-stage
# https://docs.docker.com/reference/compose-file/build/#target


# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# persistent deps
# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		file \
		git
	# pcov collects the code coverage that Infection needs for the playground runs.
	install-php-extensions \
		@composer \
		apcu \
		bcmath \
		intl \
		opcache \
		pcov \
		zip
	rm -rf /var/lib/apt/lists/*
EOF

COPY --link --chmod=755 --from=tailwind /tailwindcss /usr/local/bin/tailwindcss

# Monaco Editor, served by Caddy under /monaco/ (see frankenphp/Caddyfile).
# Its AMD build loads its own CSS, languages and web workers, which AssetMapper cannot bundle,
# so the prebuilt files are taken from the npm tarball instead of a Node.js build.
ARG MONACO_VERSION=0.20.0
ADD --link --checksum=sha256:887d527ee12a3bc1a71a1babc0e2e5f13559860c3ca16881368b3e31e1cecec9 \
	https://registry.npmjs.org/monaco-editor/-/monaco-editor-${MONACO_VERSION}.tgz /tmp/monaco-editor.tgz
RUN <<-EOF
	mkdir -p /opt/monaco
	tar -xzf /tmp/monaco-editor.tgz -C /opt/monaco --strip-components=2 package/min/vs
	rm /tmp/monaco-editor.tgz
EOF

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

###> recipes ###
###> doctrine/doctrine-bundle ###
RUN install-php-extensions pdo_mysql
###< doctrine/doctrine-bundle ###
###< recipes ###

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch

# dev dependencies
RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	install-php-extensions xdebug
	useradd -m -s /bin/bash nonroot
	git config --system --add safe.directory /app
EOF

COPY --link frankenphp/conf.d/20-app.dev.ini frankenphp/conf.d/30-app.playground.ini $PHP_INI_DIR/app.conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Builder for the prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod_builder

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link --exclude=frankenphp/ . ./

RUN <<-EOF
	mkdir -p var/cache var/log var/share
	composer dump-autoload --classmap-authoritative --no-dev
	composer dump-env prod
	composer run-script --no-dev post-install-cmd
	php bin/console tailwind:build --minify
	if [ -f importmap.php ]; then
		php bin/console asset-map:compile
	fi
	chmod +x bin/console
	chmod -R g=u var
	sync
EOF

COPY --link frankenphp/conf.d/30-app.playground.ini $PHP_INI_DIR/app.conf.d/

# Collect shared libraries needed by FrankenPHP and PHP extensions
# hadolint ignore=DL3008,SC3054,DL4006
RUN <<-'EOF'
	apt-get update
	apt-get install -y --no-install-recommends libtree
	mkdir -p /tmp/libs
	BINARIES=(frankenphp php file)
	for target in $(printf '%s\n' "${BINARIES[@]}" | xargs -I{} which {}) \
		$(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
		libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
			[ -f "$lib" ] && cp -n "$lib" /tmp/libs/
		done
	done
	rm -rf /var/lib/apt/lists/*
EOF

# Prod FrankenPHP image
FROM debian:13-slim AS frankenphp_prod

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=prod
ENV PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"

COPY --from=frankenphp_prod_builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=frankenphp_prod_builder /usr/local/bin/php /usr/local/bin/php
COPY --from=frankenphp_prod_builder /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint
COPY --from=frankenphp_prod_builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=frankenphp_prod_builder /tmp/libs /usr/lib

COPY --from=frankenphp_prod_builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=frankenphp_prod_builder /usr/local/etc/php/php.ini /usr/local/etc/php/php.ini
COPY --from=frankenphp_prod_builder /usr/local/etc/php/app.conf.d /usr/local/etc/php/app.conf.d

COPY --from=frankenphp_prod_builder /etc/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --from=frankenphp_prod_builder /opt/monaco /opt/monaco

# CA certificates for TLS, file/libmagic for Symfony MIME type detection
COPY --from=frankenphp_prod_builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=frankenphp_prod_builder /etc/ssl/openssl.cnf /etc/ssl/openssl.cnf
COPY --from=frankenphp_prod_builder /usr/bin/file /usr/bin/file
COPY --from=frankenphp_prod_builder /usr/lib/file/magic.mgc /usr/lib/file/magic.mgc

ENV  OPENSSL_CONF=/etc/ssl/openssl.cnf XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt

RUN <<-EOF
	mkdir -p /data/caddy /config/caddy
	chown -R www-data:www-data /data /config
	# Remove setuid/setgid bits
	find / -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true
EOF

COPY --link --exclude=var --exclude=infection-builds --from=frankenphp_prod_builder /app /app
# Group 0 + g=u for arbitrary-UID runtimes (e.g. OpenShift).
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/var /app/var
# Every playground run writes its sources and Infection logs to infection-builds/<id>.
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/infection-builds /app/infection-builds
RUN chmod g=u /app/var /app/infection-builds

COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

USER www-data

WORKDIR /app

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
