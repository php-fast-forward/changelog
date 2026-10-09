# Runtime dependencies and the development lock

PHP `^8.5` and Symfony `^8.1` are the runtime baseline. Console adapts invokable
commands; Filesystem/Finder guard managed paths and discover fragments; Lock
coordinates fragment and journal transactions; Process isolates Git argv; HTTP
Client implements the GitHub boundary. Fast Forward Container, Clock and Config
provide composition, UTC time and explicitly selected PHP presentation settings.
Config is not required to initialize a consumer project.

`composer.lock` pins this repository's development and Docker image
installation. Composer consumers resolve the version constraints in `require`
with their own lock; the library's lock does not pin a consumer's dependency graph.
The shared Dockerfile installs its trusted runtime with `--no-dev --no-scripts
--no-plugins` at build time. Actions execute the included CLI; consumer
dependencies are not installed by an Action. Docker bases use explicit full
version tags; external Action code remains SHA-bound with readable version
comments. Release image builds pass COMPOSER_ROOT_VERSION as a build argument.

PHPUnit, Prophecy, Rector, ECS, PHP Parser and coverage tooling are development
requirements. Running the CLI needs none of them and does not load DevTools.
Use `composer show --locked` and the disposable packaging verification to inspect
the effective graphs. There is no `version` field in the package manifest;
published package versions derive from Git tags.
