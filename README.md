# Fast Forward Changelog

<p align="center">
  <img src="docs/_static/mascot-banner.png" alt="Dash organizing change fragments into a release history" width="840">
</p>

Independent Markdown fragments, automated PR checks and a reviewed version PR
for PHP packages. Contributors add `.changelog/*.md`; the version workflow
collects them into `CHANGELOG.md` and removes the consumed fragments.

The package is in 0.x development. Public interfaces may change before 1.0;
review release notes and integration contracts before upgrading.

[![PHP Version](https://img.shields.io/badge/php-%5E8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/)
[![Composer Package](https://img.shields.io/badge/composer-fast--forward%2Fchangelog-F28D1A.svg?logo=composer&logoColor=white)](https://packagist.org/packages/fast-forward/changelog)
[![Tests](https://img.shields.io/github/actions/workflow/status/php-fast-forward/changelog/repository-ci.yml?logo=githubactions&logoColor=white&label=tests&color=22C55E)](https://github.com/php-fast-forward/changelog/actions/workflows/repository-ci.yml)
[![License](https://img.shields.io/github/license/php-fast-forward/changelog?color=64748B)](LICENSE)
[![GitHub Sponsors](https://img.shields.io/github/sponsors/php-fast-forward?logo=githubsponsors&logoColor=white&color=EC4899)](https://github.com/sponsors/php-fast-forward)

## 🤖 GitHub Actions

Use [guided setup via CPX](docs/project-installation.md) to choose the release
branch, publication behavior and optional agent skill, then review the generated
workflow. Existing integrations are preserved. For manual setup, create
`.github/workflows/changelog.yml`:

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

    uses: php-fast-forward/changelog/.github/workflows/changelog.yml@v0

    with:
      base-branch: main
      source: tags
      comment-mode: always
      prepare-release: true
      publish: true
```

The `v0` branch follows published `0.x` releases, including potentially breaking
minor updates. Select a full release tag or SHA when you need a fixed revision.

Each contribution PR adds a fragment. This workflow checks it, updates one PR
comment and creates notes for Dependabot updates. After contributions merge,
it prepares `changelog/version`: review that PR to update the central history
and remove the consumed fragments. The workflow never merges it for you.

With `publish: true`, merging the approved version PR publishes its tag and
GitHub Release. Use `publish: false` explicitly for a validation-only pilot;
review the selected release's recovery contract before merging its version PR.
Jobs run on Ubuntu with Docker, Bash and Git; your project needs no PHP or
Composer installation for automation.
For another release branch, adjust both event filters and `base-branch`.
See [setup](resources/workflows/README.md) and [optional settings](docs/reusable-lifecycle.md#optional-settings-and-composition).
Use an optional [GitHub App](docs/github-app.md) when generated PRs need CI
without the approval required by the default token.
See [publication](docs/publication.md) for the approval and commit-evidence contract.

## 🛠️ CLI

Use PHP `^8.5` and [CPX](https://github.com/laravel/cpx) to run the CLI without
adding it to the application's Composer dependencies. From your project:

```sh
cpx --skip-local fast-forward/changelog add \
  "Fixes Markdown parsing." --category=fixed --no-interaction
cpx --skip-local fast-forward/changelog check --no-interaction
```

`--skip-local` selects CPX's separate installation. The commands create one
fragment and validate pending notes; no `init` is needed. See
[local setup and previews](docs/adoption.md#run-the-cli-with-cpx) and the
[command reference](docs/cli-fragments.md#public-cli). The [Docker CLI](docs/docker.md)
is an alternative with its own PHP runtime.

## 🧠 Agent skill

The [project installer](docs/project-installation.md) offers the bundled skill
with native version and integrity tracking. Alternatively, use [Skills CLI](docs/skills.md):

```sh
npx skills add php-fast-forward/changelog --skill fast-forward-changelog
```

NPX Skills and the native installer manage their own copies and metadata; choose
one owner per copy. The [skill](resources/skills/fast-forward-changelog/SKILL.md)
consults the selected release's help and documentation. See [scope and updates](docs/skills.md)
for installation and agent activation, or [optional Composer synchronization](docs/composer-skills.md).

## 🔌 Further integration

Use the [optional Composer/PHP integration](docs/adoption.md#php-api) for classes
inside an existing application, or
[individual actions](docs/github-actions.md) for custom automation. Localized
headings and trusted PHP templates are covered in [history and templates](docs/history.md).

## 🤝 Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and this repository's [own changelog flow](docs/self-changelog.md).
Add one fragment per observable change and run `composer check` before a PR.

## 🛡 License

MIT © 2026 [Felipe Sayao Lobato Abreu](https://github.com/coisa). See [LICENSE](LICENSE).

## 🔗 Links

[Documentation](docs/index.md) · [Packagist](https://packagist.org/packages/fast-forward/changelog) ·
[Issues](https://github.com/php-fast-forward/changelog/issues) · [Support](SUPPORT.md) ·
[Security](SECURITY.md) · [Code of Conduct](CODE_OF_CONDUCT.md)
