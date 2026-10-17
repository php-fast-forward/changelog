# Automatic CI with a GitHub App

The reusable works with `GITHUB_TOKEN` by default. GitHub requires approval for
PR workflows created by that token. This affects automation tools, including
Changesets, independently of the release implementation. An installation token
from a separate GitHub App allows ordinary PR workflows to start automatically.
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
facade does not request that permission for dependency updates without alerts.

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

Select a reviewed product revision containing App authentication. Keep the
existing event filters and job permissions from the ordinary caller, and add
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

## Adopting an App after an existing version PR

Changing `automation-actor` does not transfer an existing PR's creator to the
App. Keep the old branch and commits available for review. Prepare the App's
replacement on a distinct configured `managed-branch`, validate its exact
head, scope and CI, and then close the old PR without merging it. Preserve one
active preparation line after the transition.

Do not use GitHub's generic Update branch button to refresh the generated
transaction. Run the version preparation workflow, which replans from the live
base and writes a signed Bot commit with the validated output and evidence.
A foreign/manual head is preserved and rejected rather than overwritten.

During the pilot, keep `publish: false` and the version PR draft. Verify actual
PR workflows without `action_required`, exact-head Changelog status, sticky
feedback and a repeated preparation with no additional commit. Configuring an
App or passing isolated workflow tests is not proof that these native events
have already run successfully.
