# One packaged CLI is shared by every Docker action and local container invocation.
FROM php:8.5.11-cli-alpine3.24 AS dependencies

COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache git unzip

WORKDIR /usr/local
ARG COMPOSER_ROOT_VERSION=dev-main

COPY composer.json composer.lock LICENSE ./
COPY bin/ bin/
COPY src/ src/

RUN composer install \
        --no-dev \
        --no-plugins \
        --no-scripts \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --classmap-authoritative \
    && composer check-platform-reqs --no-dev

FROM php:8.5.11-cli-alpine3.24

RUN apk add --no-cache git \
    && git config --system --add safe.directory /github/workspace \
    && git config --system --add safe.directory /github/workspace/project

COPY --from=dependencies /usr/local/bin/changelog /usr/local/bin/changelog
COPY --from=dependencies /usr/local/src/ /usr/local/src/
COPY --from=dependencies /usr/local/vendor/ /usr/local/vendor/
COPY --from=dependencies \
    /usr/local/composer.json \
    /usr/local/composer.lock \
    /usr/local/LICENSE \
    /usr/local/

ENTRYPOINT ["/usr/local/bin/changelog"]
CMD ["list"]
