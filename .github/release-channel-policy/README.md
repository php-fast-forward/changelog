# v0 channel protection

The maintainer authorized activating only the three v0 protections on 2026-10-28.
The proposed additional main-review rule and draft CODEOWNERS were removed at the
maintainer's explicit request: this is a single-maintainer project. Existing main
rules and required checks are preserved exactly; no new reviewer is required.

## Applied configuration

| Target | Rules | Bypass | Ruleset |
| --- | --- | --- | --- |
| `refs/heads/v0` | update, no upstream fetch-and-merge exception | only App 5513989, always | 26066275 |
| `refs/heads/v0` | creation, deletion, non-fast-forward | none | 26066270 |
| `refs/tags/v0` | creation, update, deletion, non-fast-forward | none | 26066273 |

All three rulesets are active. Separate integrity and update rulesets prevent
App bypass of deletion and non-fast-forward protection. Creation is blocked for
everyone because v0 already exists. Reserving the homonymous tag prevents it from
shadowing the branch. No main, wildcard v*, or future-major restriction is added.

[applied-rulesets.json](applied-rulesets.json) records fresh server responses.
[provisioning.json](provisioning.json) records verification, exact main check
contexts and the unchanged channel SHA. [proposed-configuration.json](proposed-configuration.json)
contains the resolved request configuration with App ID 5513989; its rulesets
are now applied. The generic template and offline renderer remain available:

```sh
rtk node .github/release-channel-policy/render.mjs 5513989
```

The template's null actor ID must never be sent directly to GitHub. The renderer
rejects missing/invalid IDs and the shared Actions App ID 15368. It prints JSON
only: it does not call an API or modify remote configuration. Use an App ID,
not an installation ID or bot user ID.

## App and environment

The private organization-owned App is `fast-forward-release-channels` (Fast
Forward Release Channels), App ID 5513989, installation 176263242. Permissions
are Contents write, Workflows write, Pull requests read and implicit Metadata
read. It has no Administration or Actions permission, no webhook subscriptions,
no OAuth-on-install flow and no main-review bypass. No onboarding/integration
service is implemented by registering this App.

Initially, installation was restricted to changelog. A fresh inventory before
activation reported `repository_selection: all`. This activation did not change
that scope. Consequently the App's key currently grants authority across the
organization's repositories; per-job repository-scoped tokens limit individual
tokens, not the key's authority. Any future installation-scope changes require
explicit authorization. A separate future integration App remains a different
architecture; this App is dedicated to channel updates as a function.

The changelog environment `release-channels` allows only branch main, no tags,
no wait/reviewers, and no administrator bypass of environment protection.
`RELEASE_CHANNEL_APP_CLIENT_ID` and `RELEASE_CHANNEL_APP_SLUG` are environment
variables; `RELEASE_CHANNEL_APP_PRIVATE_KEY` is an environment secret. Do not
copy this key to repository/organization secrets or use it for publication.
One validated RSA key remains after explicitly authorized revocation of the old
keys. Authentication and the initial selected-repository scope were checked with
a temporary read-only installation token, which was then revoked. Provisioning
receipts contain public identifiers/fingerprints only, never credentials.

## Workflow activation remains separate

The no-fallback channel workflow is not active on main until this change is
reviewed and merged. The currently published
workflow accepts shared lifecycle credentials, which cannot bypass the new v0
update restriction. Until this workflow reaches main through the normal
approved PR/merge process, an attempted channel update using the old credential
will fail; v0 remains at its last released commit. Rule activation does not merge
code, publish releases, or run the updater. No such live test was performed.

Once deployed, the channel job mints a token for changelog only, verifies the App
slug, and revokes the token at job completion. The built-in token is read-only;
there is no caller-token, CHANGELOG_TOKEN or github.token fallback. Workflows write
is requested because a historical release commit can contain different workflow
files; it is an App permission, not a GITHUB_TOKEN YAML permission.

Automatic calls require the successful publication outputs and exact managed PR
merge event/SHA. Manual recovery is dispatch from main with an existing immutable
stable full release tag and its exact SHA. Both paths require a unique merged
same-repository changelog/version PR into main, the newest stable release for the
major, a published previous channel commit, fast-forward ancestry, immediate
release/tag revalidation and server-side old-OID CAS. Recovery cannot recreate
v0 under the active creation block or select arbitrary published commits.

## Accepted trust boundary and limits

The maintainer explicitly retained existing main PR/check protection without
adding another reviewer. The environment branch filter does not identify one
workflow: other code on main can reference that environment and obtain its key.
Therefore review of main changes remains the maintainer's responsibility, not an
enforced independent-approval boundary. Required checks alone do not establish
exclusive workflow access. The YAML caller guards protect the intended entrypoint
but do not prevent a different trusted main workflow from using the same secret.

GitHub restricts the actor allowed to update v0, not the PHP verifier it runs.
A stolen App key/token can fast-forward v0 to an arbitrary descendant; separate
integrity rules still prohibit deletion, recreation and non-fast-forward writes.
Contents/Workflows permissions cannot be scoped to one ref and include authority
over other unprotected refs/releases. A private key remains usable to mint tokens
until revoked. Sharing this App across repositories expands that key's impact.

Administrators, organization owners and roles allowed to edit repository rules
can change or disable these protections. Environment/App managers control secret
custody. None has a configured v0 ruleset bypass; only App 5513989 bypasses the
update rule. Shared Actions and other Apps do not. Compromised maintenance,
workflow code, dependencies, upstream Action tags or runners remain supply-chain
risks. Immutable release tags bind publication state, not benign code. No absolute
immutability or complete supply-chain isolation is claimed.

For a future per-workflow credential boundary independent of main code/runners,
an external broker could verify GitHub OIDC repository/ref/event and exact
job_workflow_ref before granting authority. No such service is deployed here.

## Verification and maintenance

Activation reads each exact ruleset and compares its target, active enforcement,
conditions, rules and bypass list with the approved payload. Effective v0 rules
must be creation, deletion, non-fast-forward and update. The complete main ruleset
response is compared with the pre-activation snapshot, preserving its current five
required checks and zero required approvals. The channel SHA is read before and
after. Tag protection is checked through its exact active ruleset; no tag, push,
release, merge or workflow dispatch is used as a live test.

`php tests/Workflow/verify.php` exercises the actual workflow guards, exclusive
credential route, offline policy invariants and renderer, plus mock HTTP and
synthetic disposable Git transactions. These tests make no network requests or
real publication. Actionlint validates the two workflows. The ordinary Composer
wrapper previously hit a development-plugin dependency error; direct PHP and
Composer with plugins disabled passed. Configuration reads establish configured
policy, not empirical evidence of rejected live writes.

Future changes, disabling/rollback, installation scope, credentials and other
major channels need their own explicit authorization. Leave v0 frozen on failure;
do not substitute a PAT, unprotect it, or move it to main for recovery.

Sources: [GitHub rules API](https://docs.github.com/en/rest/repos/rules),
[environment configuration](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments),
[App key custody](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/managing-private-keys-for-github-apps).
