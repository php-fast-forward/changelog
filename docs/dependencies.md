# Requirements and dependency compatibility

Use this guide for PHP integration requirements or a Composer resolution failure.
For CLI-only use, [CPX](adoption.md) keeps the tool's dependency installation
separate from the application's graph.

| Key requirement | Supported range / reason |
| --- | --- |
| PHP | `^8.5`, including when the CLI is run through CPX. |
| Symfony Console | `^8.1`; interactive `Ask` uses its `constraints` argument. |
| Symfony Filesystem, HTTP Client, Validator and YAML | `^7.4.23 || ^8.1.11`; excludes the vulnerable 8.0 family and earlier unpatched releases. |
| Other Symfony components | `^7.4 || ^8.0`; discovery, locks and process boundaries. |
| Fast Forward Container / Config | `~1.6.0` / `^1.7`; composition and optional explicit settings. |

The [manifest](https://github.com/php-fast-forward/changelog/blob/main/composer.json)
contains all direct requirements. No local configuration file is needed for CLI use.

## Container bound

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
8.1 requirement. [CPX with `--skip-local`](adoption.md) uses a
separate dependency installation while retaining the PHP `^8.5` requirement.
The [self-contained Docker CLI](docker.md) supplies its own PHP runtime as well.

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
transitive dependencies for the supported 7.4, mixed 8.0 and 8.1 component families while
retaining the locked development test toolchain. Console retains its own 8.1
minimum. Those jobs
run the unit and real Git/HTTP integration suites, CLI discovery and the complete
provider-based contribution recipe; PHP and PHPUnit deprecations fail the jobs.
The existing `Symfony 8.0 Component Minimums` check uses the patched 7.4 branch
for Filesystem, HTTP Client, Validator and YAML alongside the unaffected 8.0 components.
There is no secure homogeneous 8.0 graph for these four components: upstream
advisories affect every 8.0 release, with fixes in 7.4.23 and 8.1.11. See the
[Filesystem advisory](https://github.com/symfony/symfony/security/advisories/GHSA-6prc-h956-hxjq),
HTTP Client [credential scope](https://github.com/symfony/symfony/security/advisories/GHSA-gq53-v8w2-gjhj)
and [DNS rebinding](https://github.com/symfony/symfony/security/advisories/GHSA-c7hm-wqwp-f9v8) advisories,
the [Validator advisory](https://github.com/symfony/symfony/security/advisories/GHSA-4qpf-57w4-m3wc)
and the [YAML advisory](https://github.com/symfony/symfony/security/advisories/GHSA-hwfg-qwvh-2wfx).
The 8.1 job additionally verifies the corrected 8.x family. Advisory blocking
remains active in every resolution; no alert is ignored for compatibility testing.
The separate fresh Composer packaging jobs still resolve complete highest and
lowest consumer runtime graphs without development dependencies.

## Contributor builds and development tools

`composer.lock` pins this repository's development and Docker image
installation. Composer consumers resolve the version constraints in `require`
with their own lock; the library's lock does not pin a consumer's dependency graph.
The shared Dockerfile installs its trusted runtime with `--no-dev --no-scripts
--no-plugins` at build time. Actions execute the included CLI; consumer
dependencies are not installed by an Action. Docker bases use explicit full
version tags; external Actions use verified full version tags. Consumer
references select a published version or reviewed full commit. Release image builds
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
