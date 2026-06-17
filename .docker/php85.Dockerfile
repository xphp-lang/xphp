FROM php:8.5-cli-alpine

# Dedicated runtime for the `@group php85` tests -- syntax (e.g. the 8.5
# pipe operator `|>`) that the host-version parser can only tokenize on
# PHP 8.5.  These run with coverage disabled, so no xdebug/pcov here:
# just composer plus the tools composer + `make test/unit/php85` need.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache \
    git \
    unzip \
    make
