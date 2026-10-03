# Contributing

Use PHP 8.5 or newer within the Composer constraint and Composer 2. The package
owns its development tools; `fast-forward/dev-tools` is unnecessary.

```sh
composer install --no-interaction --prefer-dist --no-plugins --no-scripts
composer check
```

`composer check` validates metadata and platform requirements, syntax, method
PHPDoc, the development gates, Rector dry-run, ECS and unit coverage. CI runs
the same command on Linux and Windows with PHP 8.5. Development Composer
plugins are disabled. Install does not run a project bootstrap script.

Run `composer test:unit` for a focused change. To select a test, use
`vendor/bin/phpunit --testsuite unit --filter MethodOrClass`. Production tests
mirror the source namespace under `FastForward\Changelog\Tests`. Every test
MUST document coverage through PHPUnit attributes and exercise observable
behavior, failures and preservation of state.

Unit tests MUST replace filesystem, Git, subprocess, network, host configuration,
clock and identification boundaries with doubles. They MUST NOT perform real I/O
or read HOME. Coverage includes every PHP file in `src/`, including adapters and
factories; do not remove a production path to make the gate pass. Concrete value
construction is not an excuse to access external state. Disposable integration,
packaging and workflow checks belong outside the unit suite, in
`tests/Integration`, `tests/Packaging` or `tests/Workflow`, with their own opt-in
configuration and entrypoint when added. `composer quality:verify` is an
explicit disposable integration check of the quality scripts; its fixtures do
not count toward production coverage.

`composer test:coverage` requires PCOV or Xdebug with coverage enabled. It writes
Clover to `.build/coverage.xml`, an HTML report to `.build/coverage/`, and a
per-class terminal report. `tests/verify-coverage.php` requires 100% executable
line coverage for every production file and class and rejects missing,
malformed or inconsistent evidence. Coverage is evidence of exercised lines;
review must still judge the assertions and isolation.

Every production method, including constructors and private methods, MUST have
an explanatory PHPDoc summary. Describe intent, invariants, observable effects,
exceptions and failure conditions where they apply. Use native types for
ordinary signatures and PHPDoc for shapes, generics and extra semantics. RFC
2119/8174 terms such as MUST describe actual obligations. `composer phpdoc`
rejects missing summaries and signature-only tags; reviewers verify their
accuracy. Keep the existing MIT copyright and author headers.

```sh
composer rector:fix
composer ecs:fix
composer check
```

Rector targets PHP 8.5 explicitly and includes incremental code quality, dead
code and native type declaration rules. ECS uses PER Coding Style with ordered
imports and conservative PHPDoc whitespace fixes. Neither tool removes useful
contract descriptions. Inspect every autofix, especially public API changes and
new executable branches. Run both checks again after fixes to verify convergence.

Keep command orchestration thin, inject boundary collaborators, and keep the
single implementation of changelog rules in this package. Update the guides and
examples whenever a public contract changes. Preserve deterministic rendering.
The [foundation map](docs/foundation.md) distinguishes reusable library setup
from Changelog runtime choices.

Submit a focused PR with the concrete problem, resulting behavior and exact
validation. CI success applies to its observed commit. A code change, an
approved review, merge, a tag and a published release are distinct actions.

## Community and reporting

Participation follows the [Code of Conduct](CODE_OF_CONDUCT.md). Use
[SUPPORT.md](SUPPORT.md) for questions, bugs and feature proposals. Report
suspected vulnerabilities privately as described in [SECURITY.md](SECURITY.md).
