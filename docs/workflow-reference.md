# Workflow and Action reference

Start with the [README caller](https://github.com/php-fast-forward/changelog#-github-actions) for a complete
integration, then use this page to customize it. The [lifecycle guide](reusable-lifecycle.md)
explains events and review; [individual recipes](github-actions.md) show modular callers.

This reference follows the YAML in the same revision as this document. Use the
documentation at your selected release tag or reviewed commit when integrating.
Inputs belong under `with`, reusable secrets under `secrets`, and permissions
under the calling job's `permissions`. Workflow inputs have declared types;
Action inputs are strings, including values such as `'true'` and `'false'`.
`''` means an empty string. A required input has no default.

## Reusable workflows

Use a reusable as a **job**, for example
`uses: php-fast-forward/changelog/.github/workflows/changelog.yml@v0`.
The `v0` branch follows published `0.x` releases and can cross breaking minors;
a full tag or reviewed SHA fixes the implementation.

| Name used below | Workflow | Responsibility |
| --- | --- | --- |
| Lifecycle | [`changelog.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog.yml) | Compose checks, feedback, Dependabot, version PRs and optional publication. |
| Check | [`changelog-check.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-check.yml) | Read-only check of an exact contribution. |
| Feedback | [`changelog-pr-status.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-pr-status.yml) | Exact-head status and sticky PR comment. |
| Dependabot | [`changelog-dependabot.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-dependabot.yml) | Add a fragment from verified dependency metadata. |
| Version | [`changelog-version.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-version.yml) | Prepare or refresh one managed version PR. |
| Publish | [`changelog-publish.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-publish.yml) | Publish the approved managed PR's merge commit. |
| History | [`changelog-history.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-history.yml) | Backfill or format trusted history on the runner. |

### Shared workflow inputs

Every row is optional. The applicability column is part of the contract: an
input absent from a workflow cannot be passed to it. “All” means all seven above.

| Input | Type | Default | Applies to | Meaning |
| --- | --- | --- | --- | --- |
| `runs-on` | string | `'["ubuntu-latest"]'` | All | JSON array of Linux runner labels. The runner needs Docker, Bash and Git. |
| `fragment-directory` | string | `.changelog` | All | Pending fragment path, relative to the selected project. |
| `changelog-file` | string | `CHANGELOG.md` | All | Central history path, relative to the selected project. |
| `locale` | string | `en` | All | Structural presentation language: `en` or `pt-BR`. |
| `tag-prefix` | string | `v` | All | Exact prefix of stable SemVer release tags. |
| `source` | string | `auto` | All | Historical notes source: `auto`, `github` or `tags`. This does not disable PR-origin lookup. |
| `working-directory` | string | `.` | Check, Dependabot, Version, Publish, History | Directory below the consumer checkout. PR operations require `.`; History can select a nested directory. |
| `template` | string | `keep-a-changelog` | Check, Dependabot, Version, Publish, History | Built-in template or explicitly trusted base PHP template. Check accepts only the built-in template. |
| `base-ref` | string | `HEAD` | Check, Dependabot, Version, Publish, History | Local planning revision; keep `HEAD` for automation. |
| `repository` | string | `''` | Check, Dependabot, Version, Publish, History | Target `owner/name`; empty selects the caller repository. |
| `resolve-pull-requests` | boolean | `true` | Lifecycle, Check, Feedback, Version, Publish | Resolve missing fragment PR references in versioning/publication. Check and Feedback use the setting for transaction ownership without origin lookup. |
| `managed-branch` | string | `changelog/version` | Lifecycle, Check, Feedback, Version, Publish | Exclusive generated version branch. Use a different branch for each release line. |
| `automation-actor` | string | `github-actions[bot]` | Lifecycle, Check, Feedback, Version, Publish | Expected authenticated Bot for signed managed transactions. A name alone grants no authority. |
| `waiver-label` | string | `changelog-not-required` | Lifecycle, Check, Feedback | Contribution waiver label; requires a verified current maintainer's grant. |
| `maintenance-label` | string | `changelog-maintenance` | Lifecycle, Check, Feedback | History-maintenance label; requires a verified current maintainer's grant. |
| `app-client-id` | string | `''` | Lifecycle, Feedback, Dependabot, Version, Publish, History | Optional GitHub App Client ID, paired with `app-private-key`. |

Lifecycle and Feedback work at repository root with the built-in template; they
do not expose `working-directory`, `template`, `base-ref` or `repository`.
Use repository-relative fragment/history paths for a nested package. Keep all
transaction settings consistent across separately composed operations.

### Lifecycle inputs

The Lifecycle accepts its applicable shared rows above and these six optional inputs:

| Input | Type | Default | Meaning |
| --- | --- | --- | --- |
| `base-branch` | string | `''` | Maintained release line; empty selects the repository default branch. Match the caller's event filters. |
| `version-pr-title` | string | `chore: update changelog` | Literal title or template with `{current_version}` and `{next_version}`. |
| `comment-mode` | string | `always` | `always`, `on-failure` or `never`; see [comment behavior](reusable-lifecycle.md). |
| `prepare-release` | boolean | `true` | Prepare/update the version PR on a push to the base or manual dispatch. |
| `publish` | boolean | `true` | Publish after the configured managed PR is approved and merged. Set `false` for validation; it never merges the PR. |
| `dry-run` | boolean | `false` | Preview version/publication without remote content writes. Contribution feedback and Dependabot writes remain separate operations. |

For a pilot, set `publish: false`. Merging a version PR then updates central
history without a tag or GitHub release. A later plan uses the highest stable
version in the selected base's history and ancestral tags; missing publication
of an earlier consolidation does not block the next PR. Previously recorded
sections stay intact. Enabling publication does not publish old consolidations
retroactively; see [publication](publication.md).

### Individual workflow inputs

Each workflow accepts its applicable shared inputs plus **all** rows for its name:

| Workflow | Input | Type | Required / default | Meaning |
| --- | --- | --- | --- | --- |
| Check | `pull-request` | number | Required | PR number from the `pull_request` event. |
| Check | `head-sha` | string | Required | Exact event PR head, used for the read-only checkout. |
| Check | `base-sha` | string | Required | Exact event base used for comparison. |
| Feedback | `pull-request` | number | Required | Open PR to validate. |
| Feedback | `head-sha` | string | Required | Exact event head or verified new automation head. |
| Feedback | `base-sha` | string | `''` | Exact comparison base; empty resolves the live PR base before validation. |
| Feedback | `base-branch` | string | `''` | Release line; empty selects the repository default branch. |
| Feedback | `comment-mode` | string | `always` | `always`, `on-failure` or `never`. |
| Dependabot | `pull-request` | number | Required | Dependabot PR number from the trusted event. |
| Dependabot | `head-sha` | string | Required | Exact inspected Dependabot head. |
| Dependabot | `security-alert-numbers` | string | `'[]'` | JSON alert-number array from trusted PR-associated metadata; needs alert-read permission. |
| Dependabot | `include-dev` | boolean | `true` | Include development dependency updates. |
| Dependabot | `include-actions` | boolean | `true` | Include GitHub Actions dependency updates. |
| Version | `base-branch` | string | `main` | Maintained release branch. Unlike Lifecycle, this default is literally `main`. |
| Version | `title` | string | `chore: update changelog` | Title accepting `{current_version}` and `{next_version}`. |
| Version | `dry-run` | boolean | `false` | Plan without creating GitHub content. |
| Publish | `base-branch` | string | `main` | Configured maintained release branch. |
| Publish | `pull-request` | number | Required | Merged configured version PR. |
| Publish | `target-sha` | string | Required | Full approved merge SHA from that PR's closed event. |
| Publish | `expected-head-sha` | string | Required | Original immutable version PR head from the same event. |
| Publish | `dry-run` | boolean | `false` | Verify and preview without tag/release writes. |
| History | `base-branch` | string | `main` | Trusted branch containing the selected history/template. |
| History | `operation` | string | `backfill` | `backfill` imports missing stable releases; `format` normalizes structure. |
| History | `dry-run` | boolean | `true` | Preview without local writes. |
| History | `check` | boolean | `false` | Fail when history would change. Set `dry-run: false` when enabling this mode. |

History never commits or pushes its local result. Its `dry-run: true` default
differs from the low-level History Action's default.

### Workflow secrets and authentication

| Secret | Required | Applies to | Meaning |
| --- | --- | --- | --- |
| `token` | No | All | Existing authorized Bot/App token. Empty uses `github.token`; Lifecycle's PR-head Check always uses its ordinary read-only token. |
| `app-private-key` | No | All except Check | GitHub App private key paired with `app-client-id`; each trusted job creates its own installation token. |

Use either an existing `token` or the App pair, never both. For managed PRs,
`automation-actor` must match the authenticated Bot. Keys and installation tokens
stay inside their job, not workflow outputs. An App can trigger downstream PR
CI under GitHub's token rules; see the [credential setup](github-app.md).

### Workflow permissions and events

Every reusable starts with `permissions: {}` and narrows permissions per job.
The caller must permit the following maximum set for its chosen operation;
repository/organization policy can still restrict it.

| Workflow | Caller job permissions | Intended event |
| --- | --- | --- |
| Lifecycle | `contents: write`, `pull-requests: write`, `issues: read`, `statuses: write` | Caller routes `pull_request`, `pull_request_target`, base `push` and `workflow_dispatch`. |
| Check | `contents: read`, `pull-requests: read`, `issues: read` | `pull_request` with exact head/base identities. |
| Feedback | `contents: read`, `pull-requests: write`, `issues: read`, `statuses: write` | Trusted `pull_request_target`, or selected-base push/manual event for generated heads. |
| Dependabot | `contents: write`, `pull-requests: read` | Trusted `pull_request_target` for a verified same-repository Dependabot PR. |
| Version | `contents: write`, `pull-requests: write`, `issues: read` | Push/manual dispatch on the maintained base. |
| Publish | `contents: write`, `pull-requests: read` | Closed, merged configured PR under `pull_request_target`. |
| History | `contents: read` | Trusted base maintenance, normally manual dispatch. |

An App needs the corresponding installation permissions. Explicit security
alerts additionally require Dependabot alerts read permission on the chosen
token; it is not enabled merely by listing an alert number. PR creation with
the default token also needs the repository's Actions PR-creation setting.

### Workflow outputs

All outputs are strings. An output is empty when its operation did not provide
it, including a skipped Lifecycle job. Read it through
`${{ needs.changelog.outputs.<name> }}`. Individual operations expose `result`
as JSON; parse it with `fromJSON(...)` after checking availability.

| Workflow | Outputs |
| --- | --- |
| Lifecycle | `version-pr` (reported managed PR number), `version-head` (generated head SHA), `published-version`, `published-tag`, `published-sha` (publication target, including previews). |
| Check | `result`, `status`, `fragments` (accepted fragment count). |
| Feedback | `result` (includes feedback/diagnostics), `status` (`valid` or `invalid`). |
| Dependabot | `result`, `status`, `path` (fragment path), `head-sha` (verified resulting head). |
| Version | `result`, `status`, `version`, `pull-request`, `url`, `head-sha`, `plan-id`, `maintenance` (maintenance-only indicator). |
| Publish | `result`, `state`, `version`, `tag`, `sha`, `url` (GitHub release URL). |
| History | `result`, `status`. |

The Lifecycle has no `result` output. Do not confuse its `version-pr-title` input
with the individual Version workflow's `title`, or its `version-pr` output with
the individual workflow's `pull-request`.

`published-*` values can also describe a dry-run target; they alone do not prove
that a tag or GitHub Release exists. Check the operation's successful result and
exclude `dry-run` before using them to trigger distribution. Individual Publish
reports `state: published` or `unchanged` for confirmed publication and
`state: dry-run` for a preview. The Lifecycle exposes no publication-state output;
its caller must also know that `dry-run` is disabled and the publication route
actually ran. A successful preparation-only run is not publication evidence.

## Docker Actions

The six domain Actions are step-level building blocks. Prefer the reusable
workflows unless you need to own checkout, event validation, authentication,
permissions, concurrency and feedback orchestration yourself. They build the
trusted runtime from the selected revision's Dockerfile and need a Linux runner
with Docker. They do not install the consumer's Composer dependencies.

| Name used below | Action path | Matching reusable |
| --- | --- | --- |
| Check | [`.github/actions/check`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/check/action.yml) | `changelog-check.yml` |
| Feedback | [`.github/actions/pr-status`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/pr-status/action.yml) | `changelog-pr-status.yml` |
| Dependabot | [`.github/actions/dependabot`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/dependabot/action.yml) | `changelog-dependabot.yml` |
| Version | [`.github/actions/version`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/version/action.yml) | `changelog-version.yml` |
| Publish | [`.github/actions/publish`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/publish/action.yml) | `changelog-publish.yml` |
| History | [`.github/actions/history`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/history/action.yml) | `changelog-history.yml` |

### Shared Action inputs

All inputs are strings and all rows below are optional. “All” means the six
domain Actions above. Paths are relative to the checked-out consumer project.

| Input | Default | Applies to | Meaning |
| --- | --- | --- | --- |
| `working-directory` | `.` | All | Consumer directory below the mounted workspace, never the runtime installation. |
| `fragment-directory` | `.changelog` | All | Pending fragment path. |
| `changelog-file` | `CHANGELOG.md` | All | Central history path. |
| `locale` | `en` | All | Structural presentation: `en` or `pt-BR`. |
| `tag-prefix` | `v` | All | Stable-version tag prefix. |
| `repository` | `${{ github.repository }}` | All | Consumer `owner/name`. |
| `source` | `auto` | All | Historical notes source: `auto`, `github` or `tags`. |
| `token` | `${{ github.token }}` | All | Token with the chosen operation's permissions; pass it as an input, not an Action secret. |
| `template` | `keep-a-changelog` | Check, Dependabot, Version, Publish, History | Built-in template or trusted PHP template; PR Check permits only the built-in template. |
| `base-ref` | `HEAD` | Check, Dependabot, Version, Publish, History | Local planning revision. |
| `resolve-pull-requests` | `'true'` | Check, Feedback, Version, Publish | Discover references during version/publication; Check/Feedback only verify transaction settings. |
| `managed-branch` | `changelog/version` | Check, Feedback, Version, Publish | Exclusive generated branch. |
| `automation-actor` | `github-actions[bot]` | Check, Feedback, Version, Publish | Expected authenticated managed Bot. |
| `waiver-label` | `changelog-not-required` | Check, Feedback | Label for verified contribution waiver. |
| `maintenance-label` | `changelog-maintenance` | Check, Feedback | Label for verified history maintenance. |

### Operation-specific Action inputs

Each Action accepts its applicable shared rows and all its rows below:

| Action | Input | Required / default | Meaning |
| --- | --- | --- | --- |
| Check | `since` | Required | Immutable contribution baseline; the reusable supplies its `base-sha` here. |
| Check | `pull-request` | `''` | PR number for GitHub-backed exception authority. |
| Feedback | `pull-request` | Required | Open PR number. |
| Feedback | `head-sha` | Required | Exact checked-out head. |
| Feedback | `base-sha` | Required | Exact live PR base. |
| Feedback | `base-branch` | `${{ github.event.repository.default_branch }}` | Maintained release line. |
| Feedback | `publish-status` | `'false'` | Write the exact-head commit status when `'true'`; this Action does not post a comment itself. |
| Dependabot | `pull-request` | Required | Verified Dependabot PR number. |
| Dependabot | `expected-head-sha` | Required | Immutable inspected head; the reusable calls this input `head-sha`. |
| Dependabot | `dependency-names` | Required | Comma-separated package names from verified metadata. |
| Dependabot | `dependency-type` | Required | Verified `direct:production`, `direct:development` or `indirect`. |
| Dependabot | `ecosystem` | Required | Verified package ecosystem. |
| Dependabot | `security-alert-numbers` | `'[]'` | Trusted PR-associated alert-number JSON array. |
| Dependabot | `include-dev` | `'true'` | Include development dependency updates. |
| Dependabot | `include-actions` | `'true'` | Include GitHub Actions updates. |
| Version | `base-branch` | `main` | Maintained base branch. |
| Version | `title` | `chore: update changelog` | Literal title or `{current_version}` / `{next_version}` template. |
| Version | `dry-run` | `'false'` | Plan without remote content writes. |
| Publish | `target-sha` | Required | Full approved publication target. |
| Publish | `pull-request` | `''` | Merged version PR number; required by managed-PR publication. |
| Publish | `expected-head-sha` | `''` | Original immutable version head; required with `pull-request`. |
| Publish | `base-branch` | `main` | Maintained release branch. |
| Publish | `dry-run` | `'false'` | Verify/preview without remote writes. |
| History | `operation` | `backfill` | `backfill` or `format`. |
| History | `dry-run` | `'false'` | Preview without local writes; unlike the reusable, this Action defaults to applying locally. |
| History | `check` | `'false'` | Fail when maintenance would change history; mutually exclusive with `dry-run`. |

Optional inputs are not bypasses. Publication still enforces its approved-target
contract; see [publication and recovery](publication.md). A consumer that needs
the managed-PR route should use the reusable, where all three publication
identities are required.

### Action outputs and permissions

Read outputs through `${{ steps.changelog.outputs.<name> }}`. All six Actions
return string outputs; unavailable fields are empty. Their `result` output is
the complete JSON operation result.

| Action | Outputs beyond `result` | Token permissions |
| --- | --- | --- |
| Check | `status`, `fragments` | Contents, PRs and issues read. |
| Feedback | `status`, `head-sha` | Contents, PRs and issues read; statuses write when `publish-status: 'true'`. Comment writing is separate. |
| Dependabot | `status`, `path`, `head-sha` | Contents write, PRs read; alert read for explicit alerts. |
| Version | `status`, `version`, `pull-request`, `url`, `head-sha`, `plan-id`, `maintenance` | Contents/PRs write, issues read. |
| Publish | `state`, `version`, `tag`, `sha`, `url` | Contents write, PRs read for the managed-PR route. |
| History | `status` | Contents read; historical GitHub reads need a suitable token. |

### Orchestration helpers

[`github-auth`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/github-auth/action.yml) and
[`setup-docker`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/setup-docker/action.yml) support the reusables;
they are not additional changelog lifecycle operations. Their complete inputs
are listed here for readers composing the underlying steps. All are strings.

| Helper | Input | Required / default | Meaning |
| --- | --- | --- | --- |
| `github-auth` | `app-client-id` | `''` | Optional App Client ID. |
| `github-auth` | `app-private-key` | `''` | Matching private key, retained only in this job. |
| `github-auth` | `token` | `''` | Existing token; mutually exclusive with App credentials. |
| `github-auth` | `fallback-token` | Required | Built-in job token used without custom authentication. |
| `github-auth` | `repository` | Required | Exact receiving `owner/name`. |
| `github-auth` | `operation` | Required | `version`, `dependabot`, `publish`, `status`, `comment` or `history`, selecting fixed minimum token permissions. |
| `github-auth` | `read-dependabot-alerts` | `'false'` | Request alert-read permission for explicit trusted metadata. |
| `github-auth` | `base-branch` | `''` | Selected release line for trusted push/manual events. |
| `github-auth` | `automation-actor` | `''` | Expected managed Bot when ownership is verified. |
| `setup-docker` | `dockerfile` | `Dockerfile` | Trusted runtime Dockerfile whose public base images are preloaded. |

`github-auth` outputs only `token`, for subsequent steps in the same job.
Never expose it through a job/workflow output. `setup-docker` has no outputs;
it skips persistent self-hosted daemons. Neither helper grants permissions that
the caller or App installation lacks.

---

[Documentation index](index.md)
