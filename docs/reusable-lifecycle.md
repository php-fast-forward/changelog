# One reusable changelog lifecycle

Use `.github/workflows/changelog.yml` as the consumer entrypoint. It composes the
existing operations, infers the PR revisions and default branch from the event,
validates generated heads and updates one PR comment. See the
**[complete parameter reference](workflow-reference.md)** for every input,
default, secret, output and permission. No initialization file,
Composer install, Node runtime or consumer shell script is required.

The [automation map](automation-map.md) identifies this public entrypoint, its
optional individual operations and this package's own event workflows. Copy
the [consumer caller](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/changelog.yml); keep reusable
implementations in `.github/workflows`, as required by GitHub.

## Install the workflow

Copy the [caller example](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/changelog.yml) into the consumer's
`.github/workflows/changelog.yml`. The example follows the `v0` branch, which
selects published `0.x` releases. Minor updates can change contracts; select a
full tag or SHA for a fixed revision. The
[example's setup notes](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/README.md) describe branch filters,
runner selection and the optional token.

The caller enables version preparation and explicitly sets `publish: true`,
which is also this revision's default:
contributions prepare a reviewable version PR, and merging that approved PR
publishes its tag and GitHub release. The workflow never merges it for you.

For an optional validation-only trial, explicitly add `publish: false` to
`with`. Set `prepare-release: false` as well when testing only contribution
checks and comments. Remove those overrides for the normal lifecycle.

The caller grants the maximum required permissions; each internal job reduces
them. PR-head checks use the ordinary read-only token. Feedback executes the
pinned runtime against data, including fork data, without running consumer
Composer scripts or PHP templates.
Dependabot fragment generation remains active during a trial; use a disposable PR to
validate its writes before adopting the workflow broadly.

Review the approved-merge publication contract before merging the version PR.
The workflow never merges its own version PR. `dry-run: true`
previews version/publication operations without their remote content writes;
contribution status and comments remain feedback operations.

Version preparation and publication are separate. With `publish: false`, a
merged version PR still records its version in `CHANGELOG.md`. The next plan
uses the highest stable version from the selected base's central history and
ancestral tags, preserving prior sections and consuming only new fragments.
For example, a recorded `1.7.1` followed by a patch prepares `1.7.2` even when
`1.7.1` has no tag or GitHub release. Enabling publication does not retroactively
publish older consolidations; review any recovery separately.

## Visible lifecycle results

The workflow summary separates preparation from publication and explains an
explicit `publish: false`, previews and failed stages. The generated PR body also
explains the publication setting. Existing failed jobs remain failed; reporting
does not convert a partial operation into success.

With `prepare-release: false`, the summary reports preparation as disabled instead
of implying that this run creates a version PR. Publication remains a separate setting.

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
explicit; the [reference](workflow-reference.md) lists the complete contract,
including shared settings and operation-specific differences.
The defaults prepare a version PR and publish its approved merge; explicit
`publish: false` is the validation-only override.

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
