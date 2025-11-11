FROM composer:2 AS deps-composer

COPY composer.* /var/composer/
RUN composer --working-dir=/var/composer --no-scripts --ignore-platform-reqs install

FROM php:8.3-alpine

RUN apk --virtual .build add build-base php83-dev && \
    pecl install protobuf && \
    apk del .build && \

    apk add tini \
    # gd requirements
    libzip-dev libpng-dev jpeg-dev freetype-dev icu-dev \
    # pdo_sqlite requirements
    sqlite-dev \
    # install the extensions
    && docker-php-ext-install pdo_sqlite gd intl \
    && echo "extension=protobuf" > /usr/local/etc/php/conf.d/docker-php-ext-protobuf.ini

ENTRYPOINT ["/sbin/tini", "--"]

RUN addgroup -g 1000 deploy \
    && adduser -D -G deploy -u 1000 deploy

COPY --chown=deploy:deploy . /app
COPY --from=deps-composer /var/composer/vendor /app/vendor

USER deploy
WORKDIR /app

CMD  ["php", "-S", "0.0.0.0:1215", "-t", "public"]