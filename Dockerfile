# One packaged CLI is shared by every Docker action and local container invocation.
FROM php:8.5.11-cli-alpine3.24@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb AS dependencies

COPY --from=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache git unzip
WORKDIR /opt/changelog
COPY composer.json composer.lock LICENSE ./
COPY bin/ bin/
COPY src/ src/
RUN COMPOSER_ROOT_VERSION=dev-main composer install --no-dev --no-plugins --no-scripts --prefer-dist --no-interaction --no-progress --classmap-authoritative \
    && composer check-platform-reqs --no-dev

FROM php:8.5.11-cli-alpine3.24@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb
RUN apk add --no-cache git \
    && git config --system --add safe.directory /github/workspace \
    && git config --system --add safe.directory /github/workspace/project
COPY --from=dependencies /opt/changelog /opt/changelog
ENTRYPOINT ["/opt/changelog/bin/changelog"]
CMD ["list"]
