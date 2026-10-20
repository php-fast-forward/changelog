# One reusable changelog lifecycle

Use `.github/workflows/changelog.yml` as the consumer entrypoint. It composes the
existing operations, infers the PR revisions and default branch from the event,
validates generated heads and updates one PR comment. No initialization file,
Composer install, Node runtime or consumer shell script is required.

The [automation map](automation-map.md) identifies this public entrypoint, its
optional individual operations and this package's own event workflows. Copy
the [consumer caller](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/changelog.yml); keep reusable
implementations in `.github/workflows`, as required by GitHub.

## Start with validation

Copy the [caller example](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/changelog.yml) into the consumer's
`.github/workflows/changelog.yml`. The example follows the `v0` branch, which
selects published `0.x` releases. Minor updates can change contracts; select a
full tag or SHA for a fixed revision. The
[example's setup notes](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/README.md) describe branch filters,
runner selection and the optional token.

Public repositories must also review [GitHub Actions event policies](actions-event-policy.md).
The lifecycle needs `pull_request_target` for trusted feedback and approved-merge
publication; the consumer's policy must permit that caller and event.

The example enables `prepare-release: true` to validate the reviewable version
PR and keeps `publish: false`. Set `prepare-release: false` when initially
testing only contributions and comments.

The caller grants the maximum required permissions; each internal job reduces
them. PR-head checks use the ordinary read-only token. Feedback executes the
pinned runtime against data, including fork data, without running consumer
Composer scripts or PHP templates. Publication remains disabled by default.
Dependabot fragment generation remains active; use a disposable pilot PR to
validate its writes before adopting the workflow broadly.

Only enable `publish: true` after the pilot
has validated the approved-merge publication contract and publication is
authorized. The workflow never merges its own version PR. `dry-run: true`
previews version/publication operations without their remote content writes;
contribution status and comments remain feedback operations.

## Operation coverage

| Task | What the lifecycle does |
| --- | --- |
| Validate a contribution | Check the exact event head/base and require new valid fragments. |
| Preview versioning | `dry-run` computes the plan without version/publication content writes. |
| Show PR changes | Count added valid fragments, excluding inherited inventory. |
| Update PR feedback | Maintain one sticky comment and the checked commit's status. |
| Handle Dependabot | Create one verified dependency fragment and validate the resulting head. |
| Prepare a version | Create or refresh one signed-Bot version PR from the current base. |
| Check generated heads | Validate them in the current run, even when another PR workflow needs token-event approval. |
| Publish | Use the closed, merged managed PR's original head and exact approved merge SHA. |

An empty inventory does not authorize publication. History backfill/formatting
is an individual operation, not an automatic step in this lifecycle.

Dependabot package identifiers use a deliberately restricted character set.
Ordinary Composer, GitHub Actions and Docker names fit that set; names containing
colons, including Maven coordinates such as `org.example:artifact`, are rejected.
The lifecycle does not claim unrestricted support for every Dependabot ecosystem.

## Comments and failure behavior

`comment-mode` accepts:

| Mode | Behavior |
| --- | --- |
| `always` (default) | Create or update one comment after successful or failed checks. |
| `on-failure` | Create/update on failure. On recovery, update a previous failed comment without creating a new success-only comment. |
| `never` | Record validation without creating or updating comments. |

The preview includes the checked head/base, policy, added fragment categories
and semantic impact, and actionable diagnostics. Text from PR files is escaped,
mentions/autolinks are suppressed and the preview is bounded. Full historical
Markdown is never rewritten for feedback. Operational failures have a controlled
fallback comment; unavailable evidence is never described as a successful check.
Moved, retargeted or closed PRs do not receive stale feedback. Cancellation does
not attempt to post a comment.

The CLI writes JSON feedback even for an invalid contribution and exits with
status 1. The workflow preserves that failure while allowing the comment job to
run. A comment cannot waive the contribution gate.

## Generated heads and required status

The trusted feedback operation records a fixed commit status named
`Fast Forward Changelog`, attached to the exact PR head. It records `pending`
before validation and confirms the live PR/head/base again before recording
`success` or `failure`. Uncertain execution leaves a blocking pending status.
The checked accepted fragment bytes must match committed regular blobs.

Require this exact status in the pilot repository's branch protection, with
up-to-date checks. Do not mistake a green default-branch workflow run for a
successful check of the generated head. Status, permissions, signed ownership
and merge settings must be observed on the GitHub platform during the pilot.

This validates the changelog contribution directly in the current run. Other
consumer tests still follow GitHub's token/event rules: runs created by
`GITHUB_TOKEN` require approval. For automatic downstream CI, configure the
optional App Client ID/private key so each trusted job creates its own token.
Its Bot identity must match `automation-actor`. See the
[App setup and credentials](github-app.md); a compatible token already issued
elsewhere remains supported through `secrets.token`.

## Optional settings and composition

All lifecycle inputs are optional. The copyable caller makes several choices
explicit; omitted values use these reusable defaults:

| Input | Default | Purpose |
| --- | --- | --- |
| `runs-on` | `'["ubuntu-latest"]'` | JSON array of Linux runner labels, propagated to every nested job. |
| `base-branch` | `''` (repository default branch) | Release branch; adjust the caller's event filters to match. |
| `fragment-directory` | `.changelog` | Repository-relative pending fragment directory. |
| `changelog-file` | `CHANGELOG.md` | Repository-relative central history. |
| `locale` | `en` | Language for generated headings and messages. |
| `tag-prefix` | `v` | Prefix for version tags. |
| `source` | `auto` | Historical release notes source: `auto`, `github` or `tags`. |
| `resolve-pull-requests` | `true` | Resolve missing fragment PR references during versioning and recompute them before publication; ownership checks use the same setting without doing origin lookup. |
| `managed-branch` | `changelog/version` | Branch for the single version PR. |
| `version-pr-title` | `chore: update changelog` | PR title, optionally containing `{current_version}` and `{next_version}`. |
| `automation-actor` | `github-actions[bot]` | Expected Bot identity for managed transactions. |
| `app-client-id` | `''` | Optional GitHub App Client ID; pair with `secrets.app-private-key` and the App's Bot identity. |
| `waiver-label` | `changelog-not-required` | Label used by the verified maintainer waiver policy. |
| `maintenance-label` | `changelog-maintenance` | Label used by the verified history-maintenance policy. |
| `comment-mode` | `always` | Sticky feedback: `always`, `on-failure` or `never`. |
| `prepare-release` | `true` | Prepare/update the version PR on base pushes or manual dispatch. |
| `publish` | `false` | Enable publication from an approved, merged managed PR. |
| `dry-run` | `false` | Preview version/publication without remote content writes. |

The optional secrets are `token` and `app-private-key`. With no custom
authentication, jobs use `github.token`. Supply either an already issued Bot
`token`, or the App Client ID/private-key pair, never both. Set
`automation-actor` to that App's Bot identity. PR-head checks continue using the
ordinary read-only token; private keys and generated tokens do not cross job
output boundaries. [Complete setup](github-app.md).

Keep settings consistent across all operations. PR operations work at repository
root; select nested packages through repository-relative managed paths.

`resolve-pull-requests` defaults to `true` in automation and `false` locally.
It enriches rendered notes without editing fragments; explicit metadata remains
unchanged. Set it to `false` to keep only explicit references. This is independent
of `source: tags`: enabled origin lookup still reads GitHub. Checks/feedback carry
the value for ownership but perform no origin lookup. Keep it consistent across
separately composed operations. Changing it for a prepared PR requires
regeneration and review of the new head; see [reference evidence](history.md).

To show the version transition, configure the lifecycle with:

```yaml
    with:
      version-pr-title: 'chore(release): {current_version} → {next_version}'
```

Values come from the validated plan. The individual workflow/Action uses `title`
for the same setting; see [title rules and repeatability](version-pull-request.md).

The public default is `ubuntu-latest`, without repository-specific variables.
All individual lifecycle reusables also accept `runs-on`. A consumer with an
existing authorized self-hosted runner can select its labels explicitly:

```yaml
    with:
      runs-on: '["self-hosted", "Linux", "X64"]'
```

The selected Linux runner must have Docker, Bash and Git available and be allowed
to run the called public workflows.
PR metadata queries use the official GitHub Script action and the Actions runner's
bundled JavaScript runtime; installing GitHub CLI or jq on a self-hosted runner is
not required. Runner registration, isolation and repository access belong
to the consumer's infrastructure. Use persistent runners only for work authorized
under that runner's trust policy, including same-repository contributors.

GitHub documents self-hosted access for called workflows owned by the same user
or organization as the caller. Passing labels alone does not establish access
to a runner across owners; verify the actual scheduling and runner-group policy
before relying on that topology. See [GitHub's runner rules](https://docs.github.com/en/actions/reference/workflows-and-actions/reusing-workflow-configurations#self-hosted-runners).
The optional Docker cache setup skips self-hosted runners without modifying or
restarting their persistent daemon.

Outputs expose the version PR number/head and published version/tag/SHA. They
allow a consumer to attach its own post-publication tasks. Docker image
publication is product-specific and remains separate. The packaged runtime is
still built from its pinned Dockerfile; publishing an image does not implicitly
switch the actions to consuming GHCR.

The individual reusable workflows remain supported for advanced composition;
the lifecycle does not replace their business services. See [the complete operation
contracts](github-actions.md), [publication](publication.md) and
[isolated workflow fixtures](https://github.com/php-fast-forward/changelog/blob/main/tests/Workflow/README.md).

## Pilot acceptance before release

Validate PR success and missing/malformed fragments, a fork, waiver grant/removal,
Dependabot and grouped dependency updates, version synchronization on a newer
base, generated-head status, comment recovery, reruns and cancellation. Inspect
the effective token permissions and required-status association. The version PR
must change only central history and consumed fragment deletions, with no
`Unreleased`, generated metadata comments or plan files.

Rehearse publication with `dry-run` and verify the approved target and exact notes.
Publish real tags/releases only after that pilot and explicit authorization.
Local fixtures, 100% coverage and a code review do not replace this platform test.

---

[Documentation index](index.md)
