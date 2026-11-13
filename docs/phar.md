# Compiled CLI distribution

Composer's `bin` points to the ready-built `dist/changelog` PHAR. Local installs
use `vendor/bin/changelog`; global installs use Composer's global binary path.
Consumers do not compile the CLI or install its runtime libraries. Only PHP and
the declared extensions enter the consumer dependency graph.

This replaces the former PHP library contract: service classes are no longer
registered in the consumer autoloader. The source entrypoint `php bin/changelog`
and service examples require a source checkout with development dependencies
installed, through `autoload-dev`. Applications use the CLI process. Source is maintained once, while the generated artifact
also contains copies of runtime libraries and resources.

## Official Box build

CI provisions `composer:v2, box:4.7.1` through `shivammathur/setup-php`, matching
Code Standards. No project dependency, custom compiler or installer is added.
`box.json` selects `bin`, `src`, complete `resources`, production `vendor`,
Composer manifests and license; GZ compression measured about 3 MB versus
12.7 MB uncompressed in the initial local comparison.

`extra.phar.runtime-packages` selects runtime roots from the reviewed development
lock. `php scripts/phar/prepare.php NEW_DIRECTORY` creates a Git-free staging
manifest with its own runtime autoloader and security conflicts. Composer resolves
only exact locked package records, with Packagist disabled, without plugins or
scripts. PHPUnit, Rector, ECS and Box are not runtime roots. CI then runs:

```sh
box validate --ignore-recommendations-and-warnings "$RUNTIME/box.json"
box compile --no-parallel --sort-compiled-files --config="$RUNTIME/box.json"
box verify "$RUNTIME/dist/changelog"
```

This removes a real refresh cycle: Composer otherwise embeds the checkout SHA
in its root metadata, so committing a compiled artifact changes the next build
again. Stable staging, archive timestamps and sorted inputs let the refreshed
commit converge. Box never enters the consumer or bundled dependency graph.

The generated Box stub was tested first: discovery worked, but native
`skill:install` rejected its `phar://` donor because the guarded inventory needs
a physical tree. The small `stub.php` extracts a private mode-0700 runtime,
chooses its bundled autoloader, invokes the existing entrypoint and removes that
runtime at shutdown. CWD and arguments are preserved. There is no shared cache.
Forced termination can leave that invocation's directory behind.

Explicit project PHP templates execute in the bundled CLI environment. Register
any project-specific autoload callback in that selected template. Applications
must not implicitly mix the host's Composer loader into the compiled process.
PHP service examples require this repository's development installation.

## Version metadata and platform requirements

A Composer proxy identifies its own autoloader path. The stub reads only the
adjacent `composer/installed.json` data and selects this package's version and
reference for the existing CLI configuration. It never includes the consumer's
`installed.php` or autoloader. This preserves `--version` and skill receipt
identity while executing bundled dependencies. Direct standalone invocation has
the truthful embedded development build identity; it does not invent a release
version from an ambient Git repository.

The compressed executable requires PHP `^8.5`, Phar and zlib. The bundled graph
also requires JSON, tokenizer and mbstring or its iconv-backed polyfill. Git
operations need Git. Generated-file HTTP uploads require the supported cURL
backend. The PHAR supplies neither an interpreter, extensions nor Git.

## Qualification and guarded refresh

`repository-ci.yml` builds once and downloads the producer's exact artifact ID
for PHP 8.5 on Linux, ARM and Windows. Source quality, integration/component
checks and fresh Composer local/global packaging remain independent checks.
Packaging can place the candidate only in its disposable archive copy, so a
runtime PR can qualify new bytes before refreshing its tracked executable.
Source service tests use the development graph; the compiled CLI
must ignore modifications to the package's external source and autoloader.

Only after all source, runtime and packaging gates pass does CI expose the
qualified producer artifact ID. On source PRs, a stale candidate becomes an
update artifact and parity stays red until refresh and another successful run.
Editorial PRs use the existing trusted impact classifier to skip the build.

On main, the qualified artifact is passed as data to the existing native version
Action with `base-ref` equal to the immutable event SHA and `generated-files`
selecting `dist/changelog`. History, fragment deletions and executable therefore
share one signed native version commit/PR. An obsolete main run skips before
writing; a later race is rejected by the existing planner/preflight. Explicit
feedback validates the actual resulting head. The lifecycle facade keeps
preparation disabled and uses the same generated-file policy for publication.
The new `changelog/version-phar` branch distinguishes that policy from any older
managed version branch without overwriting its evidence.

`repository-phar-refresh.yml` follows the same data-only writer pattern as Code
Standards. Its trusted workflow definition checks the original repository,
source SHA, branch and still-open PR, downloads only the qualified update from
that run, then uses the existing repository-scoped App/environment and the
marketplace autocommit Action to replace `dist/changelog` only. It does not run
consumer PHP, install dependencies, merge, tag or release. A changed/closed PR,
source-head mismatch or failed qualification prevents refresh. No credentials,
repository settings, protection rules or release channel are created here.

## Distribution and Docker

The tested executable is tracked in the same tree that will eventually be tagged,
so default Packagist/Composer dist contains it. A GitHub release asset alone would
not enter that ZIP. `--prefer-source` also receives the tracked artifact; it may
fetch more Git history and includes the source tree; default dist omits `src` and
`bin`. Complete resources and canonical skills remain outside and within the
executable. Dependency licenses are bundled inside the PHAR; the package license
is also exported outside it. Actual source and archive consumers are tested
in disposable Composer homes; no real consumer is modified by qualification.

All six public Docker Actions keep their interfaces and shared Dockerfile, which
now copies the same ready-built executable instead of resolving dependencies.
The image still supplies PHP 8.5, Git and the reviewed HTTP runtime; the PHAR
cannot replace that provisioning. No new publication channel is activated.
