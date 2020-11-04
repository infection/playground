ARG NODE_VERSION=10

FROM node:${NODE_VERSION}-alpine AS infection_nodejs

WORKDIR /srv/app

# prevent the reinstallation of vendors at every changes in the source code
COPY app/package.json app/yarn.lock ./

RUN set -eux; \
	yarn install; \
	yarn cache clean

COPY app/bin bin/
COPY app/assets assets/
COPY app/config config/
COPY app/public public/
COPY app/templates templates/
COPY app/src src/
COPY app/webpack.config.js ./
COPY app/postcss.config.js ./
COPY app/tailwind.config.js ./

RUN set -eux; \
	yarn build

COPY nodejs/docker-nodejs-entrypoint.sh /usr/local/bin/docker-nodejs-entrypoint
RUN chmod +x /usr/local/bin/docker-nodejs-entrypoint

ENTRYPOINT ["docker-nodejs-entrypoint"]
CMD ["yarn", "watch"]


FROM php:7.4.5-fpm-alpine as prod

# persistent / runtime deps
RUN apk add --no-cache \
		acl \
		fcgi \
		file \
		gettext \
		git \
		ffmpeg \
	;

RUN set -eux; \
	apk add --no-cache --virtual .build-deps \
		$PHPIZE_DEPS \
		icu-dev \
		libzip-dev \
		mysql-dev \
		zlib-dev \
	; \
	\
	git clone https://github.com/bematech/libmpdec.git \
		&& cd libmpdec \
		&& ./configure \
		&& make \
		&& make install \
		&& cd ..; \
	\
	docker-php-ext-configure zip; \
	docker-php-ext-install -j$(nproc) \
		intl \
		pdo_mysql \
		zip \
		bcmath \
	; \
	pecl install \
		apcu-5.1.18 \
		pcov \
	; \
	pecl clear-cache; \
	docker-php-ext-enable \
		apcu \
		opcache \
		bcmath \
		pcov \
	; \
	\
	runDeps="$( \
		scanelf --needed --nobanner --format '%n#p' --recursive /usr/local/lib/php/extensions \
			| tr ',' '\n' \
			| sort -u \
			| awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
	)"; \
	apk add --no-cache --virtual .api-phpexts-rundeps $runDeps; \
	\
	apk del .build-deps; \
	apk add make \
	;

COPY php/php.ini /usr/local/etc/php/php.ini

COPY --from=composer:1 /usr/bin/composer /usr/bin/composer
# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1
# install Symfony Flex globally to speed up download of Composer packages (parallelized prefetching)
RUN composer global require "symfony/flex" --prefer-dist --no-progress --no-suggest --classmap-authoritative
ENV PATH="${PATH}:/root/.composer/vendor/bin"

WORKDIR /app

COPY app .

COPY --from=infection_nodejs /srv/app/public/build/manifest.json /app/public/build/manifest.json
COPY --from=infection_nodejs /srv/app/public/build/entrypoints.json /app/public/build/entrypoints.json

ARG APP_ENV=prod

RUN mkdir -p var/cache var/logs var/sessions \
    && composer install --prefer-dist --no-dev --no-scripts --no-progress --no-suggest --classmap-authoritative --no-interaction \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd \
    && chmod +x bin/console && sync \
    && composer clear-cache \
    && chown -R www-data var \
    && chown -R www-data infection-builds

CMD ["php-fpm"]

# "nginx" production stage
# depends on the nodjs - copies assets from there
FROM nginx:1.17-alpine AS infection_nginx_prod

COPY ./nginx/prod/conf.d/default.conf /etc/nginx/conf.d/default.conf

WORKDIR /app/public

COPY --from=infection_nodejs /srv/app/public ./
