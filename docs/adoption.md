# Adopt Fast Forward Changelog

Contributors add independent `.changelog/*.md` fragments. The GitHub workflow
checks those contributions and prepares one version PR that consolidates
`CHANGELOG.md`. Publication uses the approved version PR's exact merge commit.

| What you need | Start here |
| --- | --- |
| Changelog automation on GitHub | [Guided project setup](project-installation.md) |
| Local authoring and maintenance | Run the CLI with CPX, below. |
| Instructions for an agent | [Install the optional skill](skills.md) |
| Services inside an existing PHP application | PHP API, below. |

## GitHub automation

Use [`install` via CPX](project-installation.md) when the selected release offers
it. The guided command explains publication, chooses the release branch, offers
the project skill and previews a caller before confirmation. It preserves
existing integrations and emits only non-default reusable inputs.

For manual setup, copy the [consumer workflow](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/changelog.yml)
to `.github/workflows/changelog.yml`. The [README](https://github.com/php-fast-forward/changelog/blob/main/README.md)
shows the complete caller; [setup and permissions](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/README.md)
explain its event filters and credentials. The jobs need Linux, Docker, Bash and
Git. Read those files at the selected published tag before copying them. The jobs
do not install consumer PHP, Composer dependencies or Node.js.

Choose `publish: true` for normal automatic publication after the reviewed
version PR is merged. Use `publish: false` explicitly for a validation-only pilot,
and inspect that release's publication/recovery contract before merging a version
PR without publication. Installation itself publishes nothing.

Open a contribution PR and verify its check and
sticky comment, then inspect the generated `changelog/version` PR. It should
update central history and delete the consumed fragments, without plan files,
generated metadata comments or a new `Unreleased` section. The workflow does
not merge that PR. Review the [approved-commit publication contract](publication.md)
before enabling or using publication.

Jobs default to `ubuntu-latest`; `runs-on` accepts other Linux runner labels.
Optional [GitHub App credentials](github-app.md) allow generated PR workflows
to run without the approval required by the default `GITHUB_TOKEN` route.
See [all lifecycle inputs](reusable-lifecycle.md)
and the [automation map](automation-map.md) for individual operations.

For a monorepo, run automation from the Git repository root and configure paths
such as `packages/example/.changelog` and `packages/example/CHANGELOG.md`.
Local CLI calls may instead select the package's working directory.

## Run the CLI with CPX

Use PHP `^8.5`, Git and [CPX](https://github.com/laravel/cpx). CPX resolves the
CLI in a separate installation; the application does not need to require
`fast-forward/changelog`. Put `--skip-local` before the package to select that
copy instead of a matching project-local executable.

Select the application's reviewed release, or a published stable version for new
adoption, and set `CHANGELOG_VERSION` to that numeric version. From the project
you want to maintain:

```sh
cpx --skip-local "fast-forward/changelog:$CHANGELOG_VERSION" list --raw
cpx --skip-local "fast-forward/changelog:$CHANGELOG_VERSION" help add
cpx --skip-local "fast-forward/changelog:$CHANGELOG_VERSION" add \
  "Fixes Markdown parsing." --category=fixed --no-interaction
cpx --skip-local "fast-forward/changelog:$CHANGELOG_VERSION" check --no-interaction
```

The first command lists the available commands. `help add` explains authoring.
`add` creates one unique `.changelog/*.md` file, and `check` validates the pending
inventory. No `init` or local configuration file is required. Creation does not
edit `CHANGELOG.md`, commit or push. Invalid unattended input exits nonzero
without creating a note.
See [fragment authoring](cli-fragments.md) for naming, metadata, commit options
and diagnostics.

Run from another directory by passing the CLI's `--cwd` after the package:

```sh
cpx --skip-local "fast-forward/changelog:$CHANGELOG_VERSION" check \
  --cwd=/path/to/project --no-interaction
```

After committing the contribution and fetching the intended baseline, compare
its committed changes with:

```sh
cpx --skip-local "fast-forward/changelog:$CHANGELOG_VERSION" check \
  --since=origin/main --no-interaction
```

An inherited note does not count as a new contribution. Ordinary PRs add their
own valid fragments and preserve central history and inherited fragments.
PR, issue and author metadata are optional; no follow-up edit is required just
because a PR has been opened.

CPX separates dependency installations, not PHP runtimes: PHP `^8.5` is still
required. The [Docker CLI](docker.md) supplies its own runtime. Existing Composer
CLI installations remain usable as `vendor/bin/changelog`, or `changelog` on
the global binary path; the command arguments are the same.

## Preview a release

```sh
cpx --skip-local "fast-forward/changelog:$CHANGELOG_VERSION" status --json --source=tags
cpx --skip-local "fast-forward/changelog:$CHANGELOG_VERSION" version --dry-run --source=tags
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

## PHP API

Use Composer only when the application needs the library's classes in its own
dependency graph:

```sh
composer require 'fast-forward/changelog:^0.1'
```

PHP `^8.5` and the declared runtime dependencies apply. In particular, Console
requires Symfony `^8.1`; an application restricted to Console 7.4 should use CPX
or Docker for the standalone CLI. See [dependency compatibility](dependencies.md).

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
loads the consumer autoloader, creates a fragment and checks its bytes. The
[PHP recipe](https://github.com/php-fast-forward/changelog/blob/main/resources/skills/fast-forward-changelog/references/php-api.md)
documents configuration keys, success, failure and collision behavior.
The generated API reference supplies exact signatures; the domain guides explain
[release transactions](release-receipts.md), [history](history.md) and
[publication](publication.md). [Source namespaces and migration tables](source-layout.md)
help applications adopting earlier commit snapshots.

The [skill installation guide](skills.md) offers native CLI management when the
selected release provides it, or installation through NPX Skills. It owns the
installation/check/update procedure and scope choices. Installing instructions
is separate from executing the CLI or exposing PHP classes through Composer.

---

[Documentation index](index.md)
