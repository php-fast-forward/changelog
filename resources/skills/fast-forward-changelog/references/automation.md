# GitHub lifecycle settings and acceptance

Load when extending the ordinary caller, using monorepo paths, diagnosing
feedback or rehearsing releases. The supported contract is
`fast-forward/changelog ^0.1` (minimum `0.1.0`). Select a published version or a
reviewed full commit containing the desired reusable workflow, rather than a
temporary implementation PR. Reusables stay directly in
`.github/workflows`; copy only a caller into the application.

## Settings and advanced composition

The library's `changelog*.yml` files are public reusable contracts;
`repository-*.yml` files automate the library itself. Consumers copy the caller
from `resources/workflows`, then select the lifecycle or individual operations.
The six public Docker Actions stay in `.github/actions`; `github-auth` and
`setup-docker` are internal helpers. See the product's automation map for these
boundaries. Resource paths are copyable assets, not executable reusable URLs.

The lifecycle accepts `base-branch`, `fragment-directory`, `changelog-file`,
`locale`, `tag-prefix`, `source`, `resolve-pull-requests`, `managed-branch`, `automation-actor`, `app-client-id`,
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

`resolve-pull-requests` defaults to `true` in the lifecycle and individual
version/publish operations. Preparation matches exact committed fragment bytes
and canonical paths through first-parent Git history, then accepts only verified
merged-PR repository and ancestry evidence. It adds missing PR references to the
rendered notes in memory, preserving explicit metadata and fragment bytes. It
adds no author or commit link. No qualifying PR leaves the description alone;
incomplete, ambiguous or unavailable evidence fails before managed writes.

Check/PR-feedback operations carry the same setting for the approved ownership
fingerprint without resolving fragment origins. Preserve its value across all
operations when composing them independently. `source: tags` controls historical
notes independently and does not suppress enabled PR-origin API reads. Set
`resolve-pull-requests: false` on the lifecycle to retain explicit references only;
local CLI commands use that disabled default unless their flag is selected.

Publication recomputes the origin proof and requires the approved central notes
to match exactly. A changed association or option fingerprint requires a newly
generated and reviewed version PR. Do not edit an approved head to force it
through; enabling this policy on an older prepared PR requires regeneration.
No new tracked plan, association snapshot or commit trailer carries the lookup.
Interrupted local application instead resumes the existing recovery journal's
exact saved output without repeating origin lookup.

`comment-mode` is `always`, `on-failure` or `never`. `on-failure` updates an
existing failed comment on recovery without creating a new success-only comment.
Invalid contributions retain exit/status failure even when feedback succeeds.
Comments escape untrusted fragment content and do not waive the contribution gate.
Moved, retargeted or closed PRs do not receive stale feedback. Cancellation does
not post a comment; uncertain validation leaves a blocking pending status.

Use individual reusables only when the lifecycle does not fit the consumer's
orchestration. The corresponding lower-level Docker actions are available under
`.github/actions/<operation>` at the same product pin.

| Operation | Reusable workflow | Effect |
| --- | --- | --- |
| Check | `.github/workflows/changelog-check.yml` | Validate contribution and verified policy. |
| PR Feedback | `.github/workflows/changelog-pr-status.yml` | Validate the exact head, set status and maintain one sticky comment. |
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

The lifecycle validates its generated version head in the same run. Other
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
Ordinary squash merges are supported through the exact signed head linked to
that merged PR: the publisher requires identical complete head/merge trees and
validates every release invariant against the merge. It reads immutable head
objects through the API after branch deletion, without checking out head code.
The tag remains on the approved merge; generic CLI publication remains strict
when this verified PR context is absent.
Output version/tag/SHA can feed separately authorized product tasks; Docker image
publication and any installation into another environment remain separate.
