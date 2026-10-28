# Released major tags

`repository-release-channels.yml` uses
[`Actions-R-Us/actions-tagger@v2.0.3`](https://github.com/Actions-R-Us/actions-tagger/tree/330ddfac760021349fef7ff62b372f2f691c20fb)
(verified commit `330ddfac760021349fef7ff62b372f2f691c20fb`). It receives a real
`release: published` event and promotes the matching major **tag**, such as `v0`,
to the event's `GITHUB_SHA`. It does not run from `workflow_call`, manual dispatch,
or an edited release. There is no checkout, PHP updater or Composer installation.

Only stable, non-draft releases from this repository enter the job. A short
inline guard requires a full stable `vMAJOR.MINOR.PATCH` tag before minting a token. This project
publishes stable versions only. Version `0.x` updates `v0`; version `1.x` updates
`v1` and leaves `v0` on its last `0.x` release. `latest` follows the greatest stable major and advances to `1.x` while `v0` remains unchanged. A moving tag
uses a force update intentionally; concrete version tags remain immutable.

## Activation is pending separate approval

This patch is not an activated pilot. Do not merge it until the following
publication and security changes have been reviewed and approved. No ref,
credential, environment or ruleset is changed by preparing the patch.

1. **Release event:** publication currently selects `CHANGELOG_TOKEN` or
   `github.token`. No repository-level Actions secrets were listed during
   inspection; inherited organization secrets could not be established. A release
   published with `GITHUB_TOKEN` does not start this workflow. The prepared caller
   selects App **5513989** (client ID `Iv23liHqRng02H747IYa`) only for publication
   through `publish-app-client-id` and `publish-environment: release-channels`.
   Authorizing that additional use of the App is pending. Other lifecycle jobs
   retain their credentials and `github-actions[bot]` ownership. The publication
   service still verifies that original managed-PR author independently of the
   App that authenticates the publication request. Its token requests Contents
   write and Pull requests read, already granted to the App; no installation or
   App-permission expansion is proposed.
2. **Environment:** `release-channels` exists with a secret named
   `RELEASE_CHANNEL_APP_PRIVATE_KEY`; only metadata was inspected. Migrate the existing
   key to environment secret **`app-private-key`**, the name already accepted by
   the publication reusable and now used by channel promotion too. Stage that
   name before deployment; remove the old `RELEASE_CHANNEL_APP_PRIVATE_KEY`
   name only after the reviewed workflow is deployed, so the migration remains
   recoverable. This avoids two permanent names for the same credential. The caller explicitly forwards the declared
   secret; the publication job's environment supplies its value. Do not read or
   copy any key until that configuration is authorized. Retain the public client
   ID and the same approved key; its contents have not been inspected. Keep the branch policy **`main`** for
   publication and add a **tag** deployment policy **`v*.*.*`** for release-event
   runs. No reviewer requirement, key rotation or new installation is proposed.
3. **Channel rules:** the existing `v0` tag reservation blocks the action. The
   proposed final tag rules are below. Migrating the existing `v0` branch to a tag
   at exactly its current SHA is a separately approved remote operation. Do not
   leave branch/tag name ambiguity as an undocumented permanent state.

Proposed final tag rules (App ID **5513989**, `Fast Forward Release Channels`):

| Ruleset | Targets | Rules | Bypass |
| --- | --- | --- | --- |
| Dedicated channel writer | `refs/tags/v0`, `refs/tags/v1`, `refs/tags/latest` | Restrict creation and updates | Integration `5513989`, `always` |
| Channel deletion protection | Same exact refs | Restrict deletion | None |

Set both proposed rulesets to `active` (they have not been applied). The current tag reservation (ID **26066273**) must be
replaced with these rules. Non-fast-forward restriction must not apply to these
moving tags; only the dedicated App bypasses creation/update restrictions.
The existing branch rules (**26066270**, **26066275**) remain until a separately
reviewed migration creates tag `v0` at the branch's freshly verified exact SHA,
confirms that tag, and deletes the old branch under a temporary, explicit deletion
exception. Remove that exception and the obsolete branch rules afterward. Do not
use a newer release or `main` as an incidental migration target. Keep `main` checks and zero required
approvals unchanged. No GitHub Actions shared-identity bypass is proposed.

Exact proposed ruleset payloads (configuration only, not applied):

```json
[{"target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/v0","refs/tags/v1","refs/tags/latest"],"exclude":[]}},"name":"Dedicated channel writer","bypass_actors":[{"actor_id":5513989,"actor_type":"Integration","bypass_mode":"always"}],"rules":[{"type":"creation"},{"type":"update"}]},{"target":"tag","enforcement":"active","conditions":{"ref_name":{"include":["refs/tags/v0","refs/tags/v1","refs/tags/latest"],"exclude":[]}},"name":"Channel deletion protection","bypass_actors":[],"rules":[{"type":"deletion"}]}]
```

## Guarantees and limits

The existing publication service still verifies the approved managed merge,
release content and concrete tag. This action is event-based: it does not repeat
that publication proof, accept a separately supplied expected SHA, or reconcile
an uncertain publication response.

In tag mode it compares semantic versions found in repository tags and avoids
promoting an older version when a greater version of that major is already
listed. This is not a fresh check against the GitHub Releases catalog. The
repository-wide non-cancelling lock serializes this workflow; the action does
not use an expected-old-SHA condition, revalidate evidence immediately before
writing, or enforce ancestry. An external concurrent writer can race it.
These are deliberate differences from the removed PHP updater, not equivalent
security guarantees. Only normal stable publication is in scope; future
prerelease adoption requires reviewing the action's comparison behavior.

There is no manual arbitrary-SHA recovery entrypoint. Inspect the published
release, current refs and the failed run before separately authorizing a rerun
of that original event. An old run is not a generally safe recovery selector;
review the current version tags before replaying it. Do not create or edit a
release merely to force channel promotion.

The App token is limited to `changelog`, requests Contents and Workflows write,
and is revoked after the job. Other trusted workflows able to use the same
App key can mint equivalent tokens. A stolen key/token can rewrite channel tags.
Repository/organization administrators able to edit rules can change or disable
these restrictions. Neither moving tags nor App-based bypass provides absolute
immutability or eliminates supply-chain risk.

## Verification

The optional publication inputs default to the existing credential behavior.
Consumers on a published Changelog version must first adopt a verified release
containing these inputs; the current `@v0` cannot be assumed to provide them.

`composer test:workflows` checks this repository's event, stable-release filter,
lock, App scope and Marketplace action inputs. Actionlint validates both changed
workflows. Isolated checks of the pinned action use synthetic events and a local
mock API; they do not publish releases or update GitHub refs. Such checks cannot
prove remote token permissions, event delivery or deployment-policy acceptance.
