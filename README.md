# Fast Forward Changelog

<p align="center">
  <img src="docs/_static/mascot-banner.png" alt="Dash organizing change fragments into a release history" width="840">
</p>

Independent Markdown fragments, a reviewed version PR and exact release notes
for PHP packages.

[![PHP Version](https://img.shields.io/badge/php-%5E8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/)
[![Composer Package](https://img.shields.io/badge/composer-fast--forward%2Fchangelog-F28D1A.svg?logo=composer&logoColor=white)](https://packagist.org/packages/fast-forward/changelog)
[![License](https://img.shields.io/github/license/php-fast-forward/changelog?color=64748B)](LICENSE)
[![GitHub Sponsors](https://img.shields.io/github/sponsors/php-fast-forward?logo=githubsponsors&logoColor=white&color=EC4899)](https://github.com/sponsors/php-fast-forward)

Ordinary contributions create one unique `.changelog/*.md` fragment. The version
workflow consolidates approved fragments into `CHANGELOG.md` in a separate PR.
Publication verifies the approved merge SHA and uses that document's exact note
bytes. History backfill and formatting preserve pending fragments and existing
descriptions.

## Installation

Install a release or reviewed revision containing this command surface:

```sh
composer require --dev fast-forward/changelog
vendor/bin/changelog list --raw
```

The runtime requires PHP `^8.5`; its dependencies include Symfony Console,
Filesystem and Process `^8.1`, `fast-forward/container` and `fast-forward/clock`.
It has no runtime dependency on `fast-forward/dev-tools` or the package's quality
tools. Composer global installation exposes the same executable as `changelog`.
Both installations operate on the consumer's working directory; `--cwd` selects
another project explicitly.

No initialization or local configuration file is required. Defaults use
`.changelog/`, `CHANGELOG.md`, English Keep a Changelog presentation and tag
prefix `v`, with baseline `HEAD` and history source `auto`. The fragment directory
is created only when authoring requires it. When `--repository` is omitted, a
canonical GitHub `origin` supplies `owner/name`; this inference keeps `auto` on
local tags without GitHub history requests. An explicit repository with `auto`
may use GitHub history; explicit `--source=tags` or `--source=github` selects that
source directly.

## Author a contribution

```sh
vendor/bin/changelog add "Preserves meaningful Markdown spaces." --category=fixed --no-interaction
vendor/bin/changelog check --no-interaction
vendor/bin/changelog check --since=origin/main --no-interaction
```

Select or fetch the intended baseline before checking a contribution delta.
Review and stage the exact new fragment. PR, issue and author metadata are
optional; authoring before a PR exists is supported. `--commit` opts into a local
commit containing only the generated fragment and preserves unrelated staged or
unstaged work. Ordinary contributions leave consolidation to the version workflow.

See [fragment authoring and checking](docs/cli-fragments.md) and the
[fragment schema](docs/specification/fragment-format.md).

## Commands

| Command | Purpose | Example |
| --- | --- | --- |
| `add` | Create one unique fragment | `add "Description" --category=fixed` |
| `check` | Validate inventory and contribution changes | `check --since=origin/main` |
| `status` | Inspect the release plan without writes | `status --json --source=tags` |
| `version` | Preview or apply an authorized consolidation | `version --dry-run --source=tags` |
| `notes` | Read exact notes; default to the highest maintained stable SemVer | `notes 1.2.3` |
| `backfill` | Add only missing historical versions | `backfill --dry-run --source=tags` |
| `format` | Preview presentation changes | `format --dry-run --locale=pt-BR` |
| `publish` | Verify and publish an approved commit | `publish --target-sha="$APPROVED_SHA" --repository=owner/repository --dry-run` |

Run examples through `vendor/bin/changelog`, or `changelog` with a global
installation. Use `help <command>` to inspect its arguments. `APPROVED_SHA` must
contain the complete approved commit SHA. Publication dry runs verify evidence
and read remote state. Applying version/history changes is an explicit maintainer
operation; the usual contribution route ends after adding and checking a fragment.

Shared settings include `--cwd`, `--fragment-directory`, `--changelog-file`,
`--locale`, `--template`, `--base-ref`, `--tag-prefix`, `--repository` and `--source`.
An explicitly selected trusted PHP template can customize presentation. In Git,
it must match a regular committed file at the selected base before execution;
trusted local templates remain supported outside Git. The
[adoption guide](docs/adoption.md) documents flags, exit codes and PHP contracts.

Before applying a Git-backed release, the CLI and version workflow compare the
central document, complete pending fragment set and byte hashes, and selected
template with the approved base. Keep those inputs committed at that base.
Unrelated staged or unstaged work remains allowed; `status` and fragment previews
remain useful before committing the release inputs.

## GitHub automation

Use the composite actions or reusable workflows at a reviewed immutable commit:

| Action | Reusable workflow | Responsibility |
| --- | --- | --- |
| `actions/check` | `changelog-check.yml` | Contribution checks and verified PR policy |
| `actions/dependabot` | `changelog-dependabot.yml` | One dependency-update fragment |
| `actions/version` | `changelog-version.yml` | One reviewable version PR |
| `actions/publish` | `changelog-publish.yml` | Publication from an approved consolidation SHA |
| `actions/history` | `changelog-history.yml` | Reviewed backfill/format maintenance |

Reusable workflows live in [`.github/workflows/`](.github/workflows/).
Ordinary PR checks use read-only credentials. Write operations execute trusted
base code and independently verify PR provenance and the approved target SHA.
See [GitHub action contracts](docs/github-actions.md),
[version PRs](docs/version-pull-request.md), [policy](docs/policies.md) and
[publication](docs/publication.md).

Version PRs update only the central history and delete the consumed fragments.
No plan file is added to `.changelog/`. Recovery journals stay outside the working
tree and are removed after successful application; maintenance PRs update only
the central history.

This repository uses the same runtime and local actions for its own changelog.
The [self-hosting examples](docs/self-changelog.md) explain events, defaults,
credentials and the review boundary before merging a generated version PR.

## PHP integration

Commands are plain invokable objects backed by injected services. Embed the lazy
command loader in another Symfony Console application:

```php
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use function FastForward\Container\container;

$container = container(new ChangelogServiceProvider(workingDirectory: '/path/to/project'));
$application = new Application('My Tooling');
$application->setCommandLoader($container->get(CommandLoaderInterface::class));
```

Help and command listing do not construct command services. A broken selected
dependency retains its diagnostic and leaves unrelated commands available.
Filesystem, Git, HTTP, clock, options and value factories can be replaced through
interfaces. CLI and GitHub automation share the same planning and application
services. See [adoption and service contracts](docs/adoption.md),
[transaction recovery](docs/release-receipts.md) and
[history/templates](docs/history.md).

## Agent skill

The portable procedure and license live in the real
[`.github/skills/changelog`](.github/skills/changelog/SKILL.md) package.
Repository `.agents/skills/changelog` and `.claude/skills/changelog` are relative
directory links to that single source. Copilot reads real files in `.github`.
Use the [link verifier](scripts/skills-sync.md) when maintaining this layout;
Windows checkouts without materialized symlinks are reported explicitly.
Discovery and an authorized project copy installation are documented separately
in [skill distribution](docs/skills.md); neither claims native host activation.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Run `composer check` before opening a PR.
Unit tests replace I/O collaborators with doubles and require 100% production
line coverage. `composer coverage:check` regenerates PHPUnit's native Cobertura
report and applies the same coverage gate.

[Packaging checks](tests/Packaging/README.md) install real local/global Composer
fixtures and exercise consumer CLI behavior separately from the unit suite.
The [foundation extraction map](docs/foundation.md) documents reusable quality
configuration; [skill distribution](docs/skills.md) describes the separate
project-scoped agent skill.

## Community and license

MIT © 2026 Felipe Sayao Lobato Abreu

- [Support](SUPPORT.md)
- [Security policy](SECURITY.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Sponsor Fast Forward](https://github.com/sponsors/php-fast-forward)
- [Repository](https://github.com/php-fast-forward/changelog)
- [Issues](https://github.com/php-fast-forward/changelog/issues)
- [Packagist](https://packagist.org/packages/fast-forward/changelog)
- [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
- [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
