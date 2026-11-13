# Every Docker action runs the same already compiled, self-contained CLI.
FROM php:8.5.11-cli-alpine3.24

RUN apk add --no-cache git \
    && git config --system --add safe.directory /github/workspace \
    && git config --system --add safe.directory /github/workspace/project

ARG COMPOSER_ROOT_VERSION=dev-main
ENV COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION}

COPY dist/changelog /usr/local/bin/changelog
RUN chmod 755 /usr/local/bin/changelog

ENTRYPOINT ["/usr/local/bin/changelog"]
CMD ["list"]
