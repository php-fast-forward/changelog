# Runtime dependencies and the development lock

PHP `^8.5` remains the runtime baseline. Symfony Console requires `^8.1` because
the native `Ask` attribute uses its `constraints` argument. The other Symfony
components accept `^7.4 || ^8.0`: Filesystem/Finder guard managed paths and discover fragments; Lock
coordinates fragment and journal transactions; Process isolates Git argv; HTTP
Client implements the GitHub boundary. Fast Forward Container, Clock and Config
provide composition, UTC time and explicitly selected PHP presentation settings.
Config is not required to initialize a consumer project.

Fast Forward Container retains `~1.6.0` while Config accepts `^1.7`.
Container 1.10 still retries a failed explicit concrete factory through
autowiring: an independently injected throwing factory returned a healthy
command instead of propagating the sentinel exception. The identical probe
preserves that exception exactly once under Container 1.6. The compatibility
bound therefore remains deliberate; a newer version number is insufficient.
The provider remains registered by class name without
constructor arguments. Its composition and optional configuration are exercised
against the installed packages, alongside the CLI and domain suites.

The package does not support a complete Symfony 7.4 dependency graph: an
application that requires Console 7.4 cannot install this library into the same
Composer graph. Accepting the other 7.4 components does not remove the Console
8.1 requirement. The [self-contained Docker CLI](docker.md) executes independently
of the application's Composer installation.

## Compatible Dependency Bounds

Three targeted conflicts preserve clean diagnostics on PHP 8.5:

- `php-di/php-di <7.0.7` emits implicit-nullability diagnostics while constructing
  the retained Container 1.6 composition. The 7.0.7 patch uses explicit nullable
  constructor and resolver parameters.
- `symfony/polyfill-php83 <1.30` declares implicitly nullable parameters. Loading
  it emitted PHP deprecation output before publication fixture subprocesses
  exchanged their structured results.
- `symfony/translation-contracts <2.5.3 || >=3.0,<3.4.2` has the same PHP
  diagnostic problem in its translator interface/trait. It appeared during
  native interactive validation in the test suite.

The permitted patched implementations use explicit nullable types. These
exclusions describe the reproduced diagnostic failures; they do not claim that
every application using those older packages fails. No error reporting is
disabled to make the compatibility checks pass.

The native PHP 8.5 matrix tests the maximum locked graph on Linux, ARM and
Windows. Separate component jobs minimize all runtime requirements and their
transitive dependencies for the supported 7.4 and 8.0 component families while
retaining the locked development test toolchain. Console retains its own 8.1
minimum, and the development YAML parser retains its own compatible minimum. Those jobs
run the unit and real Git/HTTP integration suites, CLI discovery and the complete
provider-based contribution recipe; PHP and PHPUnit deprecations fail the jobs.
The separate fresh Composer packaging jobs still resolve complete highest and
lowest consumer runtime graphs without development dependencies.

## Reproducible Builds And Development Tools

`composer.lock` pins this repository's development and Docker image
installation. Composer consumers resolve the version constraints in `require`
with their own lock; the library's lock does not pin a consumer's dependency graph.
The shared Dockerfile installs its trusted runtime with `--no-dev --no-scripts
--no-plugins` at build time. Actions execute the included CLI; consumer
dependencies are not installed by an Action. Docker bases use explicit full
version tags; external Actions use verified full version tags. Consumer
references to this package use reviewed immutable commits. Release image builds
pass COMPOSER_ROOT_VERSION as a build argument.

PHPUnit `^13.4`, Prophecy, Rector `^2.7`, ECS, PHP Parser and code-coverage
tooling `^14.4` are development
requirements. The separate documentation build uses the phpDocumentor shim and
Fast Forward Bootstrap template as development dependencies; only that shim is
permitted as a Composer plugin. The quality installation disables plugins. See
[documentation tooling and its isolated dependency graph](documentation.md).
Running the CLI needs none of these development tools and does not load DevTools.
Use `composer show --locked` and the disposable packaging verification to inspect
the effective graphs. There is no `version` field in the package manifest;
published package versions derive from Git tags.

---

[Documentation index](index.md)
