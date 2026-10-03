# Changelog in this repository

This package uses its own CLI and local composite Actions. Ordinary changes add
one pending fragment; the managed version PR updates `CHANGELOG.md` after those
changes reach `main`. Contributors do not maintain a central `Unreleased` list.

From the package checkout, install dependencies and create a fragment:

```sh
composer install
php bin/changelog add "Validate the approved release identity" --category fixed --type patch
php bin/changelog status
composer check
```

Commit the generated `.changelog/*.md` with the implementation. The CLI also
accepts `--commit` to commit only that new fragment. Local `php bin/changelog
check` validates pending fragments. Maintainers can preview consolidation with
`php bin/changelog version --dry-run`; applying it locally is an explicit
maintenance operation, separate from the ordinary contribution flow.

| Repository workflow | Trigger | Package operation |
| --- | --- | --- |
| [`check-changelog`](../.github/workflows/check-changelog.yml) | PR to `main`, including label changes | Check the exact contribution head with read permissions. |
| [`dependabot-changelog`](../.github/workflows/dependabot-changelog.yml) | Dependabot PR to `main` | Collect verified dependency metadata from the base context and create one deterministic fragment. |
| [`release-changelog`](../.github/workflows/release-changelog.yml) | Push to `main` | Plan from the fresh base and create or update `changelog/version`. |
| [`publish-changelog`](../.github/workflows/publish-changelog.yml) | Merge of the same-repository managed version PR into `main` | Verify its Bot, signed head and receipt, then reconcile the approved tag and GitHub Release. |

The workflows call `./actions/...` from the selected checkout. Each Action sets
up PHP 8.5 and installs this package with `--no-dev --no-scripts --no-plugins`.
The contribution job checks out the exact PR head; its token has read permissions
and checkout credentials are not persisted. The write jobs execute the trusted
base or the approved merge, never a pending PR head. Version and publication
share one concurrency group and finish without cancelling each other.

Defaults are `.changelog`, `CHANGELOG.md`, English structural headings,
`keep-a-changelog`, `v` tags and one managed branch `changelog/version`. The
contribution Action verifies labels and generated-PR authority through the
same services used by the CLI; branch names alone do not grant an exception.
An automation-created receipt records a transaction and must not be edited by
contributors. A maintenance-only receipt produces no public version.

Write operations use `secrets.CHANGELOG_TOKEN` when configured, otherwise the
job's `github.token`. Set `vars.CHANGELOG_AUTOMATION_ACTOR` to the exact Bot login
when using an App token; all three authority checks use that same setting. The
default actor is `github-actions[bot]`. The token must have the permissions
listed in its job and the repository must allow Actions to create PRs.
GitHub's event rules may require approval for CI on PRs created with the default
token; use an appropriately scoped App token when automatic downstream runs
are needed. See [GitHub's current token event rules](https://docs.github.com/en/actions/concepts/security/github_token)
and [the package's permission guidance](github-actions.md).

Dependabot metadata collection keeps actor and commit verification enabled.
Security alert IDs default to `[]`; associating an alert requires a trusted
collector and the service's live alert verification. The collector exposes
dependency names, type and ecosystem in its
[pinned output contract](https://github.com/dependabot/fetch-metadata/blob/21025c705c08248db411dc16f3619e6b5f9ea21a/action.yml).

The examples and workflows describe future repository operation. Adding and
validating them does not create a release. A generated version PR remains open
for review and approval; merging it activates the publication workflow for its
approved merge SHA. No real tag or GitHub Release is part of this implementation
session. Consumer repositories should follow [the reusable workflow guide](github-actions.md)
and pin external package references to a reviewed product commit.
