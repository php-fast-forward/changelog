# Fast Forward Changelog

<p align="center">
  <img src="docs/_static/mascot-banner.png" alt="Dash organizing change fragments into a release history" width="840">
</p>

Independent Markdown fragments, automated PR checks and a reviewed version PR
for PHP packages. Contributors add `.changelog/*.md`; the version workflow
collects them into `CHANGELOG.md` and removes the consumed fragments.

[![PHP Version](https://img.shields.io/badge/php-%5E8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/)
[![Composer Package](https://img.shields.io/badge/composer-fast--forward%2Fchangelog-F28D1A.svg?logo=composer&logoColor=white)](https://packagist.org/packages/fast-forward/changelog)
[![Tests](https://img.shields.io/github/actions/workflow/status/php-fast-forward/changelog/tests.yml?logo=githubactions&logoColor=white&label=tests&color=22C55E)](https://github.com/php-fast-forward/changelog/actions/workflows/tests.yml)
[![License](https://img.shields.io/github/license/php-fast-forward/changelog?color=64748B)](LICENSE)
[![GitHub Sponsors](https://img.shields.io/github/sponsors/php-fast-forward?logo=githubsponsors&logoColor=white&color=EC4899)](https://github.com/sponsors/php-fast-forward)

## 🤖 GitHub Actions

Create `.github/workflows/changelog.yml` in your repository:

```yaml
name: Changelog

on:
  pull_request:
    branches: [main]
    types: [opened, synchronize, reopened, ready_for_review, labeled, unlabeled]

  pull_request_target:
    branches: [main]
    types: [opened, synchronize, reopened, ready_for_review, labeled, unlabeled, closed]

  push:
    branches: [main]

  workflow_dispatch:

permissions: {}

jobs:
  changelog:
    permissions:
      contents: write
      pull-requests: write
      issues: read
      statuses: write

    uses: php-fast-forward/changelog/.github/workflows/changelog.yml@3b58dd9eacef88cc96707d169da233a8c7011904

    with:
      base-branch: main
      source: tags
      comment-mode: always
      prepare-release: true
      publish: false
```

This single caller checks contributions, updates one sticky PR comment, creates
verified Dependabot fragments and prepares the `changelog/version` PR. It uses
Docker, Bash and Git on Linux; no consumer PHP or Composer installation is needed.
Jobs use `ubuntu-latest` by default. The optional `runs-on` input selects other Linux runner labels.
Adjust both event filters and `base-branch` when your release branch is not `main`.

The example pins a reviewed implementation commit and keeps publication disabled.
Each reusable job narrows the caller's permissions; PR-head checks are read-only.
The workflow never merges its version PR. See [setup and token permissions](resources/workflows/README.md),
[all optional inputs and defaults](docs/reusable-lifecycle.md#optional-settings-and-composition) and the [automation map](docs/automation-map.md).

## 🛠️ CLI

For local authoring and release maintenance, install a release or reviewed
revision containing these commands. PHP `^8.5` and Composer are required.
Until that release is published, select `fast-forward/changelog:dev-main` for the current CLI.
Choose a project installation:

```sh
composer require --dev fast-forward/changelog:dev-main
vendor/bin/changelog add "Fixes Markdown parsing." --category=fixed --no-interaction
vendor/bin/changelog check --no-interaction
```

Or install globally and run from the repository you want to maintain:

```sh
composer global require fast-forward/changelog:dev-main
changelog add "Fixes Markdown parsing." --category=fixed --no-interaction
changelog check --no-interaction
```

The global executable must be on your `PATH`. Both installations operate on the
current project; `--cwd=/path/to/project` selects another directory. No `init`
or local configuration file is required. Use `help <command>` for options and
`check --since=origin/main` to compare a committed contribution with a fetched baseline.
The [CLI guide](docs/adoption.md) also covers `status`, `version`, `notes`,
`backfill`, `format` and `publish`, including dry runs and publication authority.

## 🧠 Agent skill

The portable [fast-forward-changelog skill](resources/skills/fast-forward-changelog/SKILL.md)
supports the `fast-forward/changelog ^1.0` contract and leads with GitHub automation,
then local CLI and optional PHP service integration. Its source is
`resources/skills/fast-forward-changelog`; a consumer installs it as
`.agents/skills/fast-forward-changelog`. See [installation and host compatibility](docs/skills.md)
or the optional [`llm/skills ^1.15` project synchronization](docs/composer-skills.md).
Installing instructions does not install the PHP CLI or establish native activation.

## 🔌 Further integration

Use the [PHP service contracts](docs/adoption.md#php-api) for an
existing application, [Docker commands](docs/docker.md) for container usage, or
[individual actions](docs/github-actions.md) for custom automation. Localized
headings and trusted PHP templates are covered in [history and templates](docs/history.md).

## 🤝 Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and this repository's [own changelog flow](docs/self-changelog.md).
Add one fragment per observable change and run `composer check` before a PR.

## 🛡 License

MIT © 2026 [Felipe Sayao Lobato Abreu](https://github.com/coisa). See [LICENSE](LICENSE).

## 🔗 Links

[Documentation](docs/index.rst) · [Packagist](https://packagist.org/packages/fast-forward/changelog) ·
[Issues](https://github.com/php-fast-forward/changelog/issues) · [Support](SUPPORT.md) ·
[Security](SECURITY.md) · [Code of Conduct](CODE_OF_CONDUCT.md)
