FROM php:8.4-cli-alpine

RUN apk add --update --no-cache linux-headers
RUN apk add --no-cache \
    php84-dev \
    build-base

RUN pecl install \
    xdebug \
    && docker-php-ext-enable \
      xdebug
