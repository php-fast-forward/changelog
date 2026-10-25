# Contributing

Use PHP 8.5 or newer within the Composer constraint and Composer 2. The package
owns its development tools; `fast-forward/dev-tools` is unnecessary.

```sh
composer install --no-interaction --prefer-dist --no-plugins --no-scripts
composer check
```

`composer check` validates metadata and platform requirements, syntax, method
PHPDoc, the development gates, Rector dry-run, ECS and unit coverage. CI runs
the same command on Linux and Windows with PHP 8.5. This quality installation
disables Composer plugins and does not run a project bootstrap script. The
separate [documentation build](docs/documentation.md) explicitly prepares the
permitted phpDocumentor shim plugin; consumers need neither that tool nor its
template dependency.

The development-only `llm/skills` plugin synchronizes dependency skills through
the `fast-forward/*` trust pattern in `skills.json`; `trusted-replace` keeps
other namespaces untrusted. Payloads come from the
installed packages rather than independent GitHub versions. After the
plugin-disabled installation above, inspect donors with `composer skills:show`
and synchronize with
`composer skills:update`. Generated `.agents/skills` files are
ignored and no host aliases are created. Ordinary plugin-enabled installs also
synchronize available dependency skills.

The runtime selects Clock `^1.1` and Container `^1.10`, which bundle their consumer
skills. Clock, Config and Container instructions follow the resolved packages;
check their installed versions and supported contracts before using them.
Continue using this repository's canonical Changelog skill directly from
`resources/skills/fast-forward-changelog`. An explicit local directory source
also restores that canonical payload into the ignored skill workspace. There
is no GitHub source for Changelog here, avoiding duplicate donor names.

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
Cobertura to `.build/coverage.xml`, Clover to `.build/clover.xml`, an HTML report
to `.build/coverage/`, and a per-class terminal report. `tests/verify-coverage.php`
requires 100% executable
line coverage for every production file and class and rejects missing,
malformed or inconsistent evidence. Coverage is evidence of exercised lines;
review must still judge the assertions and isolation.

Every production method, including constructors and private methods, MUST have
an explanatory PHPDoc summary. Describe intent, invariants, observable effects,
exceptions and failure conditions where they apply. Use native types for
ordinary signatures and preserve PHPDoc shapes, generics and extra semantics.
Each parameter needs one `@param type $name` entry explaining its role. Every
non-constructor method needs one `@return type` entry explaining the result,
including the completion effect of `void` methods. Constructors have no
`@return` entry. These descriptions must explain the contract rather than
repeat the signature. RFC
2119/8174 terms such as MUST describe actual obligations. `composer phpdoc`
rejects missing summaries, mismatched parameter tags, missing return contracts
and signature-only tags; reviewers verify their
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
