FROM composer:2 AS composer_builder

FROM php:8.0-cli

RUN apt-get update && apt-get install --no-install-recommends -yqq bash git unzip \
    && useradd -u 911 -U -d /config -s /bin/bash abc \
    && usermod -G users abc \
    && mkdir -p /app /config \
    && pecl install pcov \
    && apt-get clean \
    && rm -rf \
       	/tmp/* \
       	/var/lib/apt/lists/* \
       	/var/tmp/*

COPY --from=composer_builder /usr/bin/composer /usr/local/bin/composer
COPY docker-entrypoint.sh /usr/bin/local/docker-entrypoint

ENTRYPOINT ["/usr/bin/local/docker-entrypoint"]
