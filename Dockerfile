ARG NODE_VERSION=10

FROM php:7.2-fpm-alpine as prod

RUN apk add --no-cache \
        git

RUN set -eux \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        zlib-dev \
    && docker-php-ext-install -j$(nproc) \
        intl \
        pdo_mysql \
        zip \
        bcmath \
    && pecl install \
        apcu-5.1.12 \
    \
    && git clone --single-branch --branch=v1.0.6 --depth=1 https://github.com/krakjoe/pcov \
    && cd pcov \
    && phpize \
    && ./configure \
    && make clean install \
    && echo "extension=pcov.so" > /usr/local/etc/php/conf.d/pcov.ini \
    && cd .. \
    \
    && pecl clear-cache \
    && docker-php-ext-enable --ini-name 20-apcu.ini apcu \
    && docker-php-ext-enable --ini-name 05-opcache.ini opcache \
    && docker-php-ext-enable --ini-name 20-bcmath.ini bcmath \
    && runDeps="$( \
            scanelf --needed --nobanner --format '%n#p' --recursive /usr/local/lib/php/extensions \
                | tr ',' '\n' \
                | sort -u \
                | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
        )" \
    && apk add --no-cache --virtual .api-phpexts-rundeps $runDeps \
    && apk del .build-deps \
    && apk add --no-cache make

COPY php/php.ini /usr/local/etc/php/php.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1
# install Symfony Flex globally to speed up download of Composer packages (parallelized prefetching)
RUN composer global require "symfony/flex" --prefer-dist --no-progress --no-suggest --classmap-authoritative
ENV PATH="${PATH}:/root/.composer/vendor/bin"

WORKDIR /app

COPY app .

ARG APP_ENV=prod

RUN mkdir -p var/cache var/logs var/sessions \
    && composer install --prefer-dist --no-dev --no-scripts --no-progress --no-suggest --classmap-authoritative --no-interaction \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd \
    && chmod +x bin/console && sync \
    && composer clear-cache \
    && chown -R www-data var

CMD ["php-fpm"]

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
