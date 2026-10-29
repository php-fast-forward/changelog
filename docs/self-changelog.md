# Changelog in this repository

Use this guide when contributing to Fast Forward Changelog itself. Consumer
repositories should start with [adoption](adoption.md). This package uses the
same fragments and reusable lifecycle it distributes.

## Record a contribution

Read the root [development contract](https://github.com/php-fast-forward/changelog/blob/main/AGENTS.md)
and [canonical skill](https://github.com/php-fast-forward/changelog/blob/main/resources/skills/fast-forward-changelog/SKILL.md).
Use `php bin/changelog <command>` from this source checkout in place of the
skill's CPX package prefix. No installed copy of the skill is needed here.

```sh
composer install
php bin/changelog add "Documents the contribution flow." --category=changed --type=patch
php bin/changelog check
composer check
```

Review and commit only the generated fragment with the implementation.
`add --commit` can commit that one file explicitly; it does not push. After
committing and fetching the intended base, run
`php bin/changelog check --since=origin/main` for the committed contribution.

New changes belong in independent `.changelog/*.md` files. Do not edit central
history, modify inherited fragments or recreate `Unreleased` in an ordinary PR.
Initial development notes were curated before the first publication; their
original content remains in Git. Further editorial curation requires the
verified human maintenance policy described in [policies](policies.md).

## What automation does

| Event | Result |
| --- | --- |
| Contribution PR | Read-only contribution check, exact-head status and sticky feedback from trusted code. |
| Dependabot PR | A verified dependency fragment, followed by validation of the resulting head. |
| Push to `main` | Create or refresh the single `changelog/version` PR. |
| Merge of that version PR | Verify the approved merge and publish its tag/release. Successful publication starts image publication; major-branch promotion consumes a separate release event. |

The [automation map](automation-map.md) lists the repository CI, documentation,
lifecycle and image jobs. The lifecycle uses the public Docker Actions;
consumer dependencies and PR-head PHP never run in privileged jobs.
Version and publication share a concurrency group and do not cancel each other.

This repository enables publication for an approved merged version PR.
Preparing that PR alone creates no release. Maintainers review its exact head,
central history and fragment deletions before merging. Consumer examples start
with `publish: false`; see [the lifecycle](reusable-lifecycle.md).

## Credentials and maintenance

Lifecycle write jobs use `secrets.CHANGELOG_TOKEN` when configured, otherwise
`github.token`. `vars.CHANGELOG_AUTOMATION_ACTOR` selects the exact Bot login
(default `github-actions[bot]`). Repository policy must permit PR creation and
that token's job permissions. The prepared publication-only App route uses `publish-app-client-id` and
`publish-environment`; its activation and secret provisioning remain pending
separate approval. It keeps the managed-PR author unchanged. For automatically
triggered downstream CI, see
[GitHub App authentication](github-app.md).

Dependabot descriptions preserve the live PR title, its supplied versions and
location, then list verified packages. Security alert IDs default to `[]` and
require an independently trusted collector plus live verification; the detailed
[Dependabot policy](policies.md) owns those rules.

Local `status` and `version --dry-run` can preview a release. Applying a version
or history maintenance locally is an explicit maintainer operation, separate
from an ordinary contribution. Follow [transactions and recovery](release-receipts.md)
and [publication](publication.md); never bypass checks to force a stale plan.
For image-only failures after a published release, use [Docker recovery](docker.md).

## Released major branches and latest

The repository channel workflow pins `Actions-R-Us/actions-tagger` to reviewed
commit `330ddfac760021349fef7ff62b372f2f691c20fb` (`v2.0.3`) on real
`release: published` and `release: edited` events. The requested major channels
are **branches** `v0` and `v1`; `latest` is the action's native moving **tag**.
A 1.x release creates/updates `v1` and can advance `latest`, leaving `v0` on 0.x.
A later 0.x maintenance release can update `v0` while leaving `latest` on 1.x.
Concrete `vMAJOR.MINOR.PATCH` tags remain immutable for Composer/Packagist.
`@v0` can cross breaking development minors, unlike Composer `^0.1`.

Composer's version parser normalizes a numeric tag `v0` to `0.0.0.0`, whereas
`latest` is not a valid numeric version tag. Keeping `v0` as a branch avoids
introducing that abbreviated stable tag; numeric branches have development
stability. This does not make a moving Actions channel an immutable dependency.
See [Composer's tag and branch rules](https://getcomposer.org/doc/articles/versions.md#vcs-tags-and-branches).

On 2026-10-29, the verified full tag `@v2.0.3` resolves to commit
`330ddfac760021349fef7ff62b372f2f691c20fb`, with distributed Git blob
`0e8ec35de217f085b99b6f27e63343c3b9202c96`. The workflow uses that immutable
commit, with `v2.0.3` retained as a readable comment, so moving the upstream tag
cannot substitute the action implementation.
The resolved action force-updates major branches and always uses `tags/latest`
when latest is enabled. In branch mode its native comparison does not recognize
abbreviated `v0`/`v1` branches as full versions: an old edited release can regress
both major and latest refs without the additional selection step.

The Changelog publisher already verifies the release, full tag and exact commit.
The real release event supplies the SHA used by the action. The short inline SDK
step therefore only selects channels: it parses full stable version tags,
paginates them, compares three numeric components, and permits only the newest
tag of that major. It enables `latest` only for the newest full stable tag
globally. Editing an older release skips token minting and promotion. A version
that cannot be compared fails closed; there is no separate format validator.
A newer full tag without a release can conservatively block promotion. The
selector is not a publication catalog reconciler and does not repeat live
release/SHA checks. This smaller contract depends on publication only through
Changelog and immutable full tags enforced by the applied tag rules. Configuration
checks do not prove a future workflow execution or publication; provider and
caller deployment remain separate.
No PHP updater, checkout or manual arbitrary-SHA dispatch remains.

### Configuration applied; workflow deployment remains separate

The prepared caller selects App **5513989**, client ID
`Iv23liHqRng02H747IYa`, only for publication when repository variable
**`RELEASE_CHANNEL_PUBLICATION_ENABLED`** is exactly **`true`**. The channel job
requires the same opt-in before accessing its environment. Until that opt-in,
it retains the existing publication credential route and does not select the
publication environment. The user authorized staging on 2026-10-29. The environment key and policies
were verified before the variable was enabled; neither this configuration task
nor its checks merged code or published a release. Other lifecycle jobs retain their
credentials and managed-PR Bot. Publication still verifies that original author
independently of the App making API requests. Its requested Contents write and
Pull requests read permissions are already granted to the App. No App permission
or installation expansion is proposed for Changelog. Read-only metadata on
2026-10-28 confirms installation **176263242** is active with repository selection
**all** for `php-fast-forward`, including Code Standards. The user authorized using that existing key in Code Standards on 2026-10-29;
future distribution outside these two repositories still requires approval.
Publication using only `GITHUB_TOKEN` would not start this release-event workflow.

The environment key is **`RELEASE_CHANNEL_APP_PRIVATE_KEY`** in both repositories.
GitHub repository/environment secret names permit only letters, digits and
underscores; uploading a secret named `app-private-key` was rejected with HTTP
422. Hyphenated names such as `publish-app-private-key` and `app-private-key`
remain valid **logical workflow-call inputs**, not environment secret slots.
The lifecycle explicitly forwards `RELEASE_CHANNEL_APP_PRIVATE_KEY` to the
publication reusable. An explicitly forwarded logical `app-private-key` takes
precedence; the environment key is a fallback only when an App client ID is
selected and no explicit key is supplied. Raw-token callers ignore the staged
environment key. The channel
job reads the same valid environment key directly. No key-name migration or
permanent duplicate secret is needed.

The environments allow branch `main` and full release tags `v*.*.*`, without
additional reviewers. Their public `RELEASE_CHANNEL_APP_CLIENT_ID` variable
identifies App 5513989. Readback on 2026-10-29 verified both environments, secret names, Client IDs
and branch/tag policies. Workflow code still requires the separately reviewed
provider/caller deployments, including the valid secret-name binding. No merge or
publication is performed by configuration verification.

The applied active rulesets use these targets and bypass identities:

| Target | Restrictions | Bypass |
| --- | --- | --- |
| Branches `v0`, `v1` | Creation and update | App integration **5513989**, always |
| Same branches | Deletion and non-fast-forward | None |
| Tag `latest` | Creation and update | App integration **5513989**, always |
| Tag `latest` | Deletion | None |
| Tags `v0`, `v1` | Creation, update and deletion | None |
| Full tags `v*.*.*` | Creation | App integration **5513989**, always |
| Full tags `v*.*.*` | Update and deletion | None |

Changelog's existing branch writer (**26066275**), integrity (**26066270**) and
numeric tag reservation (**26066273**) were updated. The new rulesets are latest
writer **26099239**, latest deletion **26099240**, concrete-tag creation
**26099234**, and concrete-tag immutability **26099237**. Equivalent rules were
created for Code Standards. Full-tag creation restrictions matter because
release-event workflow code comes from the released ref and the environment
permits full version tags; arbitrary version-tag workflows could otherwise obtain
the environment key. No shared GitHub Actions or administrator bypass was added
to these channel/full-tag rules. Existing `main` rules were verified unchanged,
including current checks and approval settings.

Applied payloads, read back and verified on **2026-10-29**:

```json
[{"name":"Dedicated major branch writer","target":"branch","enforcement":"active","conditions":{"ref_name":{"include":["refs/heads/v0","refs/heads/v1"],"exclude":[]}},"rules":[{"type":"creation"},{"type":"update"}],"bypass_actors":[{"actor_id":5513989,"actor_type":"Integration","bypass_mode":"always"}]},{"name":"Major branch integrity","target":"branch","enforcement":"active","conditions":{"ref_name":{"include":["refs/heads/v0","refs/heads/v1"],"exclude":[]}},"rules":[{"type":"deletion"},{"type":"non_fast_forward"}],"bypass_actors":[]},{"name":"Dedicated latest tag writer","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/latest"],"exclude":[]}},"rules":[{"type":"creation"},{"type":"update"}],"bypass_actors":[{"actor_id":5513989,"actor_type":"Integration","bypass_mode":"always"}]},{"name":"Latest tag deletion protection","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/latest"],"exclude":[]}},"rules":[{"type":"deletion"}],"bypass_actors":[]},{"name":"Reserved numeric channel tags","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/v0","refs/tags/v1"],"exclude":[]}},"rules":[{"type":"creation"},{"type":"update"},{"type":"deletion"}],"bypass_actors":[]},{"name":"Concrete release publisher","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/v*.*.*"],"exclude":[]}},"rules":[{"type":"creation"}],"bypass_actors":[{"actor_id":5513989,"actor_type":"Integration","bypass_mode":"always"}]},{"name":"Concrete release immutability","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/v*.*.*"],"exclude":[]}},"rules":[{"type":"update"},{"type":"deletion"}],"bypass_actors":[]}]
```

There is no branch-to-tag migration: keep the existing `v0` branch at its freshly
verified current SHA. Reserve homonymous numeric tags so they cannot shadow the
branches. Future approved normal publication creates `v1`/`latest` as needed;
preparing or testing this patch does not authorize moving any of these refs.
Consumer Code Standards must first adopt a verified Changelog release containing
the new optional publication-only API; the current published `@v0` does not have
those inputs. Its installation is already covered by the organization-wide
selection. Its environment and valid secret were provisioned under the user's
2026-10-29 authorization. The local key was authenticated read-only as App 5513989
before upload; secret values were never printed. Readback verifies secret
metadata, not decryption or a live publication. The prior candidate revision
expects an invalid environment secret name; the separately reviewed caller must
adopt the corrected provider binding before the next publication.

### Remaining limits and recovery

The repository lock prevents cancellation of the running workflow, but its
default queue holds only one pending run: a newer event replaces an existing
pending run even with `cancel-in-progress: false`. A replaced 0.x event followed
by a 1.x event can leave `v0` stale. Include canceled pending runs when inspecting
recovery. See [GitHub's concurrency behavior](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency).
The lock serializes this workflow only. The action has
no expected-old-SHA CAS, ancestry check, immediate evidence revalidation or
post-write confirmation. Native non-fast-forward rules are active without
App bypass; they can reject backwards branch writes, but actual server acceptance
of the action's API calls requires authorized observation. They do not turn the
SDK snapshot and later mutation into one atomic transaction. Major and `latest`
updates are separate requests, so a partial failure can leave them inconsistent.
The workflow requests a repository-limited token, but the private key can mint
tokens for other repositories covered by the organization-wide installation.
Compromise of that key in Code Standards could therefore affect Changelog too.
Other writers with the key can race this job; deleted/rewritten evidence and
changes to the pinned action commit require fresh review. Administrators able to edit rules or
trusted workflows can change these boundaries. A stolen App key/token remains
a supply-chain risk; no absolute immutability is promised for moving channels.

For recovery, inspect the live release, full tag, current channel refs and failed
or canceled run before separately authorizing a rerun of that original event. The selector
rechecks the current tag catalog; there is no arbitrary SHA recovery input. Do
not edit a release solely to bypass a failure. Tests exercise this actual inline
selector and the resolved action against synthetic events and a local mock API;
they do not establish live token permissions or event delivery.

---

[Documentation index](index.md)
