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

The repository channel workflow uses `Actions-R-Us/actions-tagger@v2` on real
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

On 2026-10-28, upstream `@v2` resolved to commit
`330ddfac760021349fef7ff62b372f2f691c20fb`, with distributed Git blob
`0e8ec35de217f085b99b6f27e63343c3b9202c96`. This upstream channel can move.
The resolved action force-updates major branches and always uses `tags/latest`
when latest is enabled. In branch mode its native comparison does not recognize
abbreviated `v0`/`v1` branches as full versions: an old edited release can regress
both major and latest refs without the additional selection step.

The single inline SDK selection step checks the live release is published,
stable and bound to the event's tag, and that the full tag still identifies the
event SHA. It paginates existing full stable tags, compares three numeric
components, and permits only the newest tag of that major. It enables `latest`
only for the newest full stable tag globally. Editing an older release therefore
skips token minting and promotion. A newer full tag without a release can
conservatively block promotion; the selector is not a complete publication
catalog reconciler. No PHP updater, checkout or manual arbitrary-SHA dispatch
remains in this operation.

### Activation still requires separate approval

The prepared caller selects App **5513989**, client ID
`Iv23liHqRng02H747IYa`, only for publication. Other lifecycle jobs retain their
credentials and managed-PR Bot. Publication still verifies that original author
independently of the App making API requests. Its requested Contents write and
Pull requests read permissions are already granted to the App. No App permission
or installation expansion is proposed for Changelog. Publication using only
`GITHUB_TOKEN` would not start this release-event workflow.

`release-channels` currently has a `main` branch policy and a secret named
`RELEASE_CHANNEL_APP_PRIVATE_KEY` (metadata verified; key validity not rechecked).
The proposed configuration retains `main`, adds tag policy `v*.*.*`, and migrates
the key to **`app-private-key`**, the existing publication-reusable secret name
also used by channel promotion. Stage that name before deploying reviewed code;
remove the old secret name only after the deployment is confirmed. No key has
been read or copied for this patch. The caller explicitly forwards the declared
publication secret; the nested job's environment supplies its value.

Proposed active rulesets use these targets and bypass identities:

| Target | Restrictions | Bypass |
| --- | --- | --- |
| Branches `v0`, `v1` | Creation and update | App integration **5513989**, always |
| Same branches | Deletion and non-fast-forward | None |
| Tag `latest` | Creation and update | App integration **5513989**, always |
| Tag `latest` | Deletion | None |
| Tags `v0`, `v1` | Creation, update and deletion | None |
| Full tags `v*.*.*` | Creation | App integration **5513989**, always |
| Full tags `v*.*.*` | Update and deletion | None |

Update the existing branch writer (**26066275**) and integrity (**26066270**)
accordingly; expand the existing numeric tag reservation (**26066273**) to `v1`.
The other rules are proposed additions. Full-tag creation restrictions matter
because release-event workflow code comes from the released ref and the
environment permits full version tags. A writer able to publish arbitrary
version-tag workflows could otherwise obtain the environment key. Review the
impact on other tag-creating automation before applying these rules. Keep `main`
checks and zero required approvals unchanged.

Exact proposed payloads, **not applied**:

```json
[{"name":"Dedicated major branch writer","target":"branch","enforcement":"active","conditions":{"ref_name":{"include":["refs/heads/v0","refs/heads/v1"],"exclude":[]}},"rules":[{"type":"creation"},{"type":"update"}],"bypass_actors":[{"actor_id":5513989,"actor_type":"Integration","bypass_mode":"always"}]},{"name":"Major branch integrity","target":"branch","enforcement":"active","conditions":{"ref_name":{"include":["refs/heads/v0","refs/heads/v1"],"exclude":[]}},"rules":[{"type":"deletion"},{"type":"non_fast_forward"}],"bypass_actors":[]},{"name":"Dedicated latest tag writer","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/latest"],"exclude":[]}},"rules":[{"type":"creation"},{"type":"update"}],"bypass_actors":[{"actor_id":5513989,"actor_type":"Integration","bypass_mode":"always"}]},{"name":"Latest tag deletion protection","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/latest"],"exclude":[]}},"rules":[{"type":"deletion"}],"bypass_actors":[]},{"name":"Reserved numeric channel tags","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/v0","refs/tags/v1"],"exclude":[]}},"rules":[{"type":"creation"},{"type":"update"},{"type":"deletion"}],"bypass_actors":[]},{"name":"Concrete release publisher","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/v*.*.*"],"exclude":[]}},"rules":[{"type":"creation"}],"bypass_actors":[{"actor_id":5513989,"actor_type":"Integration","bypass_mode":"always"}]},{"name":"Concrete release immutability","target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/v*.*.*"],"exclude":[]}},"rules":[{"type":"update"},{"type":"deletion"}],"bypass_actors":[]}]
```

There is no branch-to-tag migration: keep the existing `v0` branch at its freshly
verified current SHA. Reserve homonymous numeric tags so they cannot shadow the
branches. Future approved normal publication creates `v1`/`latest` as needed;
preparing or testing this patch does not authorize moving any of these refs.
Consumer Code Standards must first adopt a verified Changelog release containing
the new optional publication-only API; the current published `@v0` does not have
those inputs. Its App installation/key scope requires separate verification.

### Remaining limits and recovery

The non-cancelling repository lock serializes this workflow only. The action has
no expected-old-SHA CAS, ancestry check, immediate evidence revalidation or
post-write confirmation. Native non-fast-forward rules remain proposed without
App bypass; they can reject backwards branch writes, but actual server acceptance
of the action's API calls requires authorized observation. They do not turn the
SDK snapshot and later mutation into one atomic transaction. Major and `latest`
updates are separate requests, so a partial failure can leave them inconsistent.
Other writers with the key can race this job; deleted/rewritten evidence and
upstream `@v2` changes require fresh review. Administrators able to edit rules or
trusted workflows can change these boundaries. A stolen App key/token remains
a supply-chain risk; no absolute immutability is promised for moving channels.

For recovery, inspect the live release, full tag, current channel refs and failed
run before separately authorizing a rerun of that original event. The selector
rechecks the current tag catalog; there is no arbitrary SHA recovery input. Do
not edit a release solely to bypass a failure. Tests exercise this actual inline
selector and the resolved action against synthetic events and a local mock API;
they do not establish live token permissions or event delivery.

---

[Documentation index](index.md)
