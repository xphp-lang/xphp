FROM php:8.4-cli-alpine

# Composer: copied from the official multi-arch image instead of curl-
# piped install.  Reproducible across rebuilds and gets composer 2.x
# without an interactive installer.  Both Makefile entry points
# (`make test` and `make test/mutation`) shell out to `composer
# install`, so this needs to be in PATH.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apk add --update --no-cache linux-headers
RUN apk add --no-cache \
    php84-dev \
    build-base \
    git \
    unzip

# Both coverage drivers are installed.  Xdebug runs as the dev-time
# debugger; PCOV is used exclusively for Infection's coverage pass
# (orders of magnitude less memory than Xdebug, which avoids the
# host OOM-killer firing during the initial test run on tight
# containers).  Infection auto-detects PCOV when it's loaded and
# Xdebug is disabled via XDEBUG_MODE=off; the mutation Makefile
# target does both.
RUN pecl install \
    xdebug \
    pcov \
    && docker-php-ext-enable \
      xdebug \
      pcov
