# Adopt Fast Forward Changelog

Contributors add independent `.changelog/*.md` fragments. The GitHub workflow
checks those contributions and prepares one version PR that consolidates
`CHANGELOG.md`. Publication uses the approved version PR's exact merge commit.

| What you need | Start here |
| --- | --- |
| Changelog automation on GitHub | [Guided project setup](project-installation.md) |
| Local authoring and maintenance | Run the compiled CLI, below. |
| Instructions for an agent | [Install the optional skill](skills.md) |
| Source service development | Development-only PHP services, below. |

## GitHub automation

Use the compiled [`install` command](project-installation.md) when the selected release offers
it. The guided command explains publication, chooses the release branch, offers
the project skill and previews a caller before confirmation. It preserves
existing integrations and emits only non-default reusable inputs.

For manual setup, copy the [consumer workflow](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/changelog.yml)
to `.github/workflows/changelog.yml`. The [README](https://github.com/php-fast-forward/changelog/blob/main/README.md)
shows the complete caller; [setup and permissions](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/README.md)
explain its event filters and credentials. The jobs need Linux, Bash and
Git. Read those files at the selected published tag before copying them. Actions
provision their PHP runtime without installing the consumer's Composer dependencies
or Node.js.

Publication defaults to enabled after the reviewed version PR is merged.
Installation itself publishes nothing.

Open a contribution PR and verify its check and
sticky comment, then inspect the generated `changelog/version` PR. It should
update central history and delete the consumed fragments, without plan files,
generated metadata comments or a new `Unreleased` section. The workflow does
not merge that PR. Review the [approved-commit publication contract](publication.md)
before merging it. For an explicit validation-only trial, set `publish: false`:
history still advances, but tags and GitHub Releases are not created. Later PRs
use the recorded version even when its publication remains pending.

Jobs default to `ubuntu-latest`; `runs-on` accepts other Linux runner labels.
Optional [GitHub App credentials](github-app.md) allow generated PR workflows
to run without the approval required by the default `GITHUB_TOKEN` route.
See [all lifecycle inputs](reusable-lifecycle.md)
and the [automation map](automation-map.md) for individual operations.

For a monorepo, run automation from the Git repository root and configure paths
such as `packages/example/.changelog` and `packages/example/CHANGELOG.md`.
Local CLI calls may instead select the package's working directory.

## Run the compiled CLI

Use PHP `^8.3` with Phar and zlib, Git and Composer. The package ships a ready-built PHAR; installing
it requires no build tool. Its Composer proxy executes the bundled CLI rather
than loading the application's dependencies. Runtime libraries are embedded;
only PHP and required extensions constrain the consumer graph. PHP service
classes require a source development checkout.

Select the application's reviewed release, or a published stable version for new
adoption, and set `CHANGELOG_VERSION` to that numeric version. From the project
you want to maintain:

```sh
composer require --dev "fast-forward/changelog:$CHANGELOG_VERSION"
vendor/bin/changelog list --raw
vendor/bin/changelog help add
vendor/bin/changelog add \
  "Fixes Markdown parsing." --category=fixed --no-interaction
vendor/bin/changelog check --no-interaction
```

The first command lists the available commands. `help add` explains authoring.
`add` creates one unique `.changelog/*.md` file, and `check` validates the pending
inventory. No `init` or local configuration file is required. Creation does not
edit `CHANGELOG.md`, commit or push. Invalid unattended input exits nonzero
without creating a note.
See [fragment authoring](cli-fragments.md) for naming, metadata, commit options
and diagnostics.

Run from another directory by passing the CLI's `--cwd`:

```sh
vendor/bin/changelog check \
  --cwd=/path/to/project --no-interaction
```

After committing the contribution and fetching the intended baseline, compare
its committed changes with:

```sh
vendor/bin/changelog check \
  --since=origin/main --no-interaction
```

An inherited note does not count as a new contribution. Ordinary PRs add their
own valid fragments and preserve central history and inherited fragments.
PR, issue and author metadata are optional; no follow-up edit is required just
because a PR has been opened.

For a global command, install the same selected release:

```sh
composer global require "fast-forward/changelog:$CHANGELOG_VERSION"
changelog check --no-interaction
```

Ensure Composer's global binary directory is on `PATH`. The selected release's
PHP version and required extensions remain necessary. GitHub Actions provision
PHP and execute the same compiled CLI without a container. For a project
installation, optional Composer scripts can keep the invocation short:

```json
{
  "scripts": {
    "changelog": "vendor/bin/changelog",
    "changelog:check": "@changelog check --no-interaction"
  }
}
```

Merge these entries with existing scripts, then run `composer changelog:check`
or `composer changelog -- add "Fixes Markdown parsing." --category=fixed`.

## Preview a release

```sh
vendor/bin/changelog status --json --source=tags
vendor/bin/changelog version --dry-run --source=tags
```

Both calls are read-only. Review the current/next version, effective impact,
consumed paths and rendered notes. A plan is evidence of the proposed output,
not approval to apply or publish it. The normal route remains contribution PR
→ version workflow → reviewed version PR → publication at the approved merge.

For an authorized local operation, read [transactions and recovery](release-receipts.md)
before applying a plan. Use the [history guide](history.md) for backfill, formatting,
localization and custom templates. [Publication](publication.md) documents exact
commit evidence, retries and refusal of divergent tags or notes. The
[CLI reference](cli-fragments.md) lists all commands and shared options.

## Development-only PHP services

The installed package is CLI-only and does not register its services in the
consumer autoloader. This replaces the former library contract. Applications use
`vendor/bin/changelog`. The examples below require `composer install` in a source
checkout with development dependencies, rather than a consumer installation.

CLI and automation share injected domain services. The provider has no
constructor arguments. This composition excerpt shows default and optional
configuration; it is not a complete application:

```php
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use function FastForward\Config\config;
use function FastForward\Container\container;

$container = container(ChangelogServiceProvider::class);

$container = container(
    config(['changelog' => ['working_directory' => '/path/to/project']]),
    ChangelogServiceProvider::class,
);
```

Keep the application's existing command loader. Use domain services through
that application or the standalone container; do not replace its loader just
to add changelog behavior. Constructors perform no filesystem, Git, network,
clock or credential observation. Configuration is explicit and captured lazily.

The complete [contribution program](https://github.com/php-fast-forward/changelog/blob/main/resources/skills/fast-forward-changelog/examples/contribution.php)
loads the source development autoloader, creates a fragment and checks its bytes. The
[PHP recipe](https://github.com/php-fast-forward/changelog/blob/main/resources/skills/fast-forward-changelog/references/php-api.md)
documents configuration keys, success, failure and collision behavior.
The generated API reference supplies exact signatures; the domain guides explain
[release transactions](release-receipts.md), [history](history.md) and
[publication](publication.md). [Source namespaces and migration tables](source-layout.md)
help applications adopting earlier commit snapshots.

The [skill installation guide](skills.md) uses `llm/skills` with a GitHub source,
without adding Changelog to the application's dependencies. It also covers NPX
and existing native copies. Installing instructions is separate from executing
the CLI or exposing PHP classes through Composer.

---

[Documentation index](index.md)
