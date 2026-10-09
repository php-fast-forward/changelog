# Reusable PHP library foundation

This package is an extraction reference for a future PHP library template. It
keeps the existing Changelog source and tests and owns its Composer, test and
quality entrypoints. Copy the relevant files and adapt them to the new package;
there is no generator, synchronization service or dependency on DevTools.

| Reusable base | What to adapt |
| --- | --- |
| `composer.json` structure, PSR-4 autoload, dev tools and scripts | Name, description, runtime PHP constraint, source/test namespaces, paths and package dependencies |
| `phpunit.xml.dist` unit isolation and strict coverage metadata | Test namespace, suite paths and bootstrap; add explicit integration configurations only when needed |
| `rector.php`, `ecs.php`, `scripts/` and `tests/verify-coverage.php` | Minimum PHP target, package-owned paths, style decisions and any deliberate legacy fixture directory |
| `.github/workflows/tests.yml` | PHP versions, supported operating systems, pinned Actions and branch triggers |
| `CONTRIBUTING.md` and scoped `AGENTS.md` | Actual development workflow, responsibilities and instruction hierarchy |
| `README.md`, `docs/`, PR template and badges | Real installation, public API, commands, URLs, default branch and workflow names |
| License, authors, copyright, support and funding metadata | Preserve real provenance; set actual maintainers and license instead of inventing identities |

The base dev tools are PHPUnit, its coverage component, Prophecy and its PHPUnit
integration when mocks need it, Rector, ECS and PHP Parser for the PHPDoc gate.
They stay under `require-dev`; executing the consumer CLI loads none of them.
For an ordinary library, remove `bin`, CLI smoke tests and CLI-specific runtime
dependencies. Do not invent empty classes or artificial tests for coverage.

The Changelog-specific choices are Symfony Console, Filesystem and Process
`^8.1`, Fast Forward Container and Clock, their service-provider/PSR contracts,
Composer runtime metadata and Safe. Container and Clock are focused packages;
`fast-forward/config` is also a direct dependency for optional consumer settings. They do
not depend on DevTools. A different library chooses its own runtime boundaries.
None of these packages is mandatory for every future library.

PHP is `^8.5`, with Composer's development platform fixed at `8.5.0`. Rector
uses its verified installed API `withPhpVersion(PHP_85)` and
`withPhpSets(php85: true)` so an upgrade cannot silently opt into PHP 8.6 syntax.
The initial quality, dead code and type declaration levels are deliberately
incremental. Raise a level in a reviewed change and retest public API, doubles
and coverage. ECS uses the existing PER prepared set, alphabetic imports and
PHPDoc alignment/indentation/trim without deleting semantic descriptions.

Readability rules add a blank line before `return`, `throw`, `continue` and
`break` when another statement precedes them. Constructor parameters use separate
lines; long argument lists wrap around 120 columns while intentional multiline
layouts stay multiline. Keep a blank line before each new attributed command
parameter or input property, with its attribute directly attached to that
parameter/property. Multiline argument spacing preserves these groups.
Dockerfile stages, installation flags and runtime layout use separate logical
blocks. Workflow shell guards and long commands use continuation lines without
changing their arguments or expressions. Opaque strings, class names and pinned
references may exceed the wrapping target when splitting would harm clarity.

The full local gate is `composer check`. Composer plugins are disabled and
installation runs with `--no-plugins --no-scripts`. Production coverage includes
all `src/`; PHPUnit generates native Cobertura, Clover, HTML and per-class text
reports. The small verifier enforces 100% native line totals and every executable
class, without rounding uncovered lines into success. `composer coverage:check`
regenerates the unit report through `test:coverage`, so the public gate cannot
reuse a stale report. `composer check` runs that suite once. The verifier rejects
malformed Cobertura and invalid counts, including a zero denominator.
`composer quality:verify` separately tests the gates' success and rejection
paths in temporary fixtures, then removes only the fixtures it created.

This library intentionally omits a version field and tracks `composer.lock` for
development and Docker builds. Composer consumers resolve the declared runtime
constraints through their own locks. A future template chooses its own lockfile
policy. Consumer releases obtain identity from VCS tags.

Before extracting the reference, replace repository links, docs URLs, badges,
sponsor links and author/license data with verified values. Never copy
credentials, absolute host paths, installed tool state or the DevTools bootstrap.
Keep installation/global use and Changelog release automation in the product
contracts, not in a universal library baseline.
