FROM php:8.3-cli-alpine

RUN apk add --update --no-cache linux-headers
RUN apk add --no-cache \
    php83-dev \
    build-base

RUN pecl install \
    xdebug \
    && docker-php-ext-enable \
      xdebug
