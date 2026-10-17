# GitHub lifecycle settings and acceptance

Load when extending the ordinary caller, using monorepo paths, diagnosing
feedback or rehearsing releases. The supported contract is
`fast-forward/changelog ^1.0` (minimum `1.0.0`). Pin a reviewed immutable
repository commit containing the desired reusable workflow; never substitute a
moving branch or an unmerged implementation PR. Reusables stay directly in
`.github/workflows`; copy only a caller into the application.

## Settings and advanced composition

The facade accepts `base-branch`, `fragment-directory`, `changelog-file`,
`locale`, `tag-prefix`, `source`, `managed-branch`, `automation-actor`, `app-client-id`,
`waiver-label` and `maintenance-label`. Preserve consistent values across
operations. `base-branch` otherwise follows the repository default branch.
Jobs default to `ubuntu-latest`; the optional `runs-on` input accepts a JSON
array of Linux runner labels. Docker, Bash and Git are required. Runner selection
and access belong to the application. The library's
[complete optional-input table](https://github.com/php-fast-forward/changelog/blob/main/docs/reusable-lifecycle.md#optional-settings-and-composition)
lists the defaults, including `prepare-release: true` and `publish: false`.
Use optional `version-pr-title: 'chore(release): {current_version} → {next_version}'`
to show the plan's version transition. The individual version workflow/action
uses `title`; the CLI uses `github version --title=...`. The default title stays
`chore: update changelog`. Only these two placeholders expand; maintenance uses
the current version for both. Templates may use at most 1024 bytes; literal and
fully resolved titles must remain single-line and at most 256 bytes. A title-only
rerun preserves the owned PR's body, head and release plan; `dry-run` performs no
title write.
For monorepos select paths such as `packages/example/.changelog` and
`packages/example/CHANGELOG.md`, relative to the Git repository root.
A local CLI may use a package cwd; GitHub API paths remain repository-rooted.

`comment-mode` is `always`, `on-failure` or `never`. `on-failure` updates an
existing failed comment on recovery without creating a new success-only comment.
Invalid contributions retain exit/status failure even when feedback succeeds.
Comments escape untrusted fragment content and do not waive the contribution gate.
Moved, retargeted or closed PRs do not receive stale feedback. Cancellation does
not post a comment; uncertain validation leaves a blocking pending status.

Use individual reusables only when the facade does not fit the consumer's
orchestration. The corresponding lower-level Docker actions are available under
`.github/actions/<operation>` at the same product pin.

| Operation | Reusable workflow | Effect |
| --- | --- | --- |
| Check | `.github/workflows/changelog-check.yml` | Validate contribution and verified policy. |
| Dependabot | `.github/workflows/changelog-dependabot.yml` | Create one verified dependency fragment. |
| Version | `.github/workflows/changelog-version.yml` | Synchronize one reviewable managed PR. |
| Publish | `.github/workflows/changelog-publish.yml` | Publish the approved consolidation SHA. |
| History | `.github/workflows/changelog-history.yml` | Prepare backfill/format maintenance. |

Read the chosen workflow's actual inputs and permissions before composing it.
Do not reconstruct trusted issuer/PR/commit evidence from an editable label or
PR body. Dependabot package names use restricted identifiers; names containing
colons, such as Maven coordinates, are rejected. Do not promise support for all
ecosystems merely because a bot authored the PR.

## Verify the platform behavior

Check a valid fragment, missing/malformed fragments, fork data, maintainer waiver
grant/removal, Dependabot/grouped updates, version synchronization on a newer
base, reruns, comment recovery and cancellation. Inspect the effective token
permissions, exact PR head/base, signed automation ownership and required status.
Require `Fast Forward Changelog` with up-to-date checks; a green default-branch
run is not proof for a generated head.

The facade validates its generated version head in the same run. Other
application CI still follows GitHub token/event restrictions. `GITHUB_TOKEN`
created PR events require approval. To trigger ordinary downstream workflows
automatically, install an approved App for the consumer repository and supply
its Client ID as `app-client-id`, its PEM private key as
`secrets.app-private-key` and its actual `<slug>[bot]` as `automation-actor`.
Each trusted job creates and revokes its own repository-scoped token; PR-head
checks do not receive the key. Alternatively supply an already issued Bot token
as `secrets.token`; do not mix both routes. A personal token cannot replace the
signed Bot ownership contract. No secret belongs in a job output or source file.
See the [credential and permission guide](https://github.com/php-fast-forward/changelog/blob/main/docs/github-app.md).

Changing the configured Bot does not transfer an existing PR's creator. Keep
old commits, prepare and validate the replacement on a distinct managed branch,
then close the old PR without merging it.
Existing sticky comments are also author-specific: after changing Bots, review
and retire old feedback explicitly instead of expecting the new identity to
update it. Subsequent runs reuse the App's own sticky.
Do not refresh a generated version
with a generic human merge of its base: version preparation must recreate the
validated signed transaction. A successful local fixture does not prove
consumer scheduling, native authentication or branch protection.

Keep `publish: false` in a pilot. `dry-run: true` previews version/publication
operations without their remote content writes; status and comment feedback
remain operations. The version PR must contain only central history and
consumed fragment deletions, with no generated metadata or technical plan files.
The workflow never merges it. Only separately authorized publication may enable
`publish: true`, using the exact approved merge SHA and committed evidence.
Output version/tag/SHA can feed separately authorized product tasks; Docker image
publication and any installation into another environment remain separate.
