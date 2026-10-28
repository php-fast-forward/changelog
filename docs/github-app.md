# Automatic CI with a GitHub App

Use this optional setup when CI on generated version PRs should start without
manual approval. The default `GITHUB_TOKEN` route requires that approval;
a separate GitHub App's installation token allows ordinary PR workflows to start automatically.
See [GitHub's token/event rules](https://docs.github.com/en/actions/concepts/security/github_token).

An App is optional. It is needed when the consumer requires downstream CI to
start without that approval and has no other compatible Bot token integration.
It does not enable publication or merge the version PR.

## Credentials and permissions

Create or reuse an App installed for the consumer repository. Give its
installation these repository permissions:

| Permission | Access |
| --- | --- |
| Contents | Read and write |
| Pull requests | Read and write |
| Issues | Read |
| Commit statuses | Read and write |
| Metadata | Read, implicit |

The reusable requests only the permissions needed by each operation and limits
each token to the target repository. It does not request Actions write access
or approve its own CI. A consumer that supplies its own extra jobs still owns
their credentials, triggers, permissions and runner policy.
The individual Dependabot workflow additionally requires Dependabot alerts
read access when `security-alert-numbers` selects alerts to verify; the ordinary
lifecycle does not request that permission for dependency updates without alerts.

Configure these consumer Actions settings, or organization settings explicitly
shared with that repository:

| Setting | Kind | Value |
| --- | --- | --- |
| `CHANGELOG_APP_CLIENT_ID` | Variable | The App's Client ID. This is not the installation ID or client secret. |
| `CHANGELOG_APP_BOT` | Variable | The App's actual Bot login, such as `example-release[bot]`. |
| `CHANGELOG_APP_PRIVATE_KEY` | Secret | The generated private key in PEM format, including its header, footer and newlines. |

No client secret, personal access token, webhook secret or manual installation
token is needed. Do not put the private key in Git or a conversation. An App
must be installed for the selected repository; registering it alone is not
sufficient. See [GitHub's App authentication setup](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/making-authenticated-api-requests-with-a-github-app-in-a-github-actions-workflow).

## Caller configuration

Keep the existing event filters and job permissions from the ordinary caller, and add
these settings to its reusable job:

```yaml
    with:
      app-client-id: ${{ vars.CHANGELOG_APP_CLIENT_ID }}
      automation-actor: ${{ vars.CHANGELOG_APP_BOT }}
      publish: false

    secrets:
      app-private-key: ${{ secrets.CHANGELOG_APP_PRIVATE_KEY }}
```

Client ID and private key must be supplied together. Do not also pass
`secrets.token`; that alternative accepts an already issued compatible Bot
token. A personal token is not a direct substitute: generated transactions
require the configured Bot identity and a GitHub-verified signed commit.

Each trusted job creates its own short-lived installation token. Tokens stay
inside that job, are not exposed as workflow/job outputs and use the official
action's default revocation at job completion. PR-head checks continue to use
the ordinary read-only token and do not receive the App key. Trusted code must
not execute a PR's scripts or PHP templates with the key present.

## A separate App for publication only

A caller can retain its existing PR author and credentials while using a different
App to publish the verified release. The lifecycle accepts optional
`publish-app-client-id`, `publish-environment` and secret
`publish-app-private-key`. They affect only the publication job. That job requests
Contents write and Pull requests read; it still passes the original
`automation-actor` to the publication service to verify the managed PR.

The individual publication reusable also accepts `environment`. If selected,
its `app-private-key` environment secret takes precedence for that job; callers
must explicitly forward the declared secret through the reusable chain. The
repository's own caller demonstrates this route. Environment policy, App use and
secret provisioning require separate authorization; optional inputs do not grant
that authority. This feature must be present in the consumer's verified selected
Changelog release before adding the new inputs.

### Adoption order for automatic release-channel promotion

Adding an `on: release` channel workflow does not change the publisher's
credentials. A release created using `github.token` does not start that second
workflow. The lifecycle caller must explicitly select the publication-only App.
Use a nonempty, verified public client ID; an empty optional ID retains the
normal built-in-token behavior. With a configured ID but a missing key, App
authentication fails before publication instead of falling back silently.

1. Inspect the **actual referenced revision** of `changelog.yml`: it must declare
   `publish-app-client-id`, `publish-environment` and the optional
   `publish-app-private-key` secret, and forward them to the publication job.
   The previously published Changelog channel commit
   `203db95e68fc974e95ba1a921f08d01f88851be1` does not provide this API. Do not
   send the new inputs to that revision merely because its selector is `@v0`.
2. Review the provider change and the consumer caller together. A consumer patch
   can qualify an explicit immutable candidate SHA that actually has the API;
   label it as an unpublished candidate, not as a verified stable release. Before
   stable adoption, the maintainer merges/releases the compatible Changelog
   contract and selects that verified revision. Do not invent a future tag or
   assume the moving channel has already advanced.
3. Separately approve and provision the publication environment, declared
   `app-private-key` secret, repository-scoped App use and relevant policies.
   Forward the declared secret through both reusable calls. For this repository's
   own caller, set `RELEASE_CHANNEL_PUBLICATION_ENABLED=true` only after the
   separately approved configuration is staged; otherwise its previous publication
   credential route remains active. The optional API does not provision
   credentials or authorize channel writes.
4. Merge the separately reviewed consumer caller only after its selected provider
   and configuration prerequisites are satisfied. Its next separately authorized
   managed-version-PR merge can publish through the App and emit a real release
   event. A green caller PR is not evidence of live event delivery. Do not rerun,
   edit or republish an existing release just to test the integration.

Publication-only inputs leave version preparation, feedback credentials and the
configured managed-PR author unchanged. An App key is needed only by the
publishing repository; consumers of the library or `@v0` do not receive it.

## Adopting an App after an existing version PR

Changing `automation-actor` does not transfer an existing PR's creator to the
App. Keep the old branch and commits available for review. Prepare the App's
replacement on a distinct configured `managed-branch`, validate its exact
head, scope and CI, and then close the old PR without merging it. Preserve one
active preparation line after the transition.

Existing sticky comments also belong to their original Bot. The pinned comment
action searches by that identity, so an App's first feedback on an existing
ordinary PR can create a new sticky instead of updating the old Bot's comment.
With `on-failure`, an old failure comment can remain after recovery. Review and
retire that old comment explicitly during migration; subsequent runs use the
App's own sticky. Do not infer a changed check result from the old comment.

Do not use GitHub's generic Update branch button to refresh the generated
transaction. Run the version preparation workflow, which replans from the live
base and writes a signed Bot commit with the validated output and evidence.
A foreign/manual head is preserved and rejected rather than overwritten.

During the pilot, keep `publish: false` and the version PR draft. Verify actual
PR workflows without `action_required`, exact-head Changelog status, sticky
feedback and a repeated preparation with no additional commit. Configuring an
App or passing isolated workflow tests is not proof that these native events
have already run successfully.

---

[Documentation index](index.md)
