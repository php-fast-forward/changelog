# GitHub Actions Event Policies

If a run shows **Actions policies → Restrict events → Evaluate**, GitHub is
reporting an event-policy evaluation. Check the run's jobs separately: a run can
succeed while the policy reports that it would block the same event when active.

GitHub's default policy restricts `pull_request_target` in public repositories
without an applicable event policy. It initially evaluates runs; GitHub schedules
enforcement for **November 2, 2026** for affected repositories that used the default
before general availability. Private and internal repositories are outside this
default. Check the current [GitHub announcement](https://github.blog/changelog/2026-09-17-workflow-execution-protections-in-github-actions-generally-available/)
when planning adoption.

## Why The Lifecycle Uses This Event

The caller separates operations by event:

| Event | Responsibility |
| --- | --- |
| `pull_request` | Read-only contribution checks. |
| `pull_request_target` | Trusted PR feedback, verified Dependabot fragments and publication after an approved managed version PR is merged. |
| `push` | Prepare or refresh the version PR on the configured base. |
| `workflow_dispatch` | Explicit preparation or preview supported by the consumer caller. |

The trusted operations read PR content as data and run the selected trusted
implementation. They must not build or execute PR-head code, Composer scripts
or consumer PHP templates. Changing the event to `pull_request` would change
credential access and the lifecycle contract. See [GitHub's security guide](https://docs.github.com/en/actions/reference/security/securely-using-pull_request_target)
before changing this boundary.

An event policy controls whether a run may start. It is separate from token
permissions, fork approvals, branch protection and a GitHub App. Increasing
`permissions`, replacing the token or rerunning the job does not override an
event block. A workflow cannot fix a policy that prevents it from starting.

## Review And Configure The Consumer

A repository administrator can configure repository policies; organization and
enterprise owners manage their respective scopes. Open the **consumer
repository's Settings → Actions → Policies** and inspect its applicable policies
and **Policy insights**, where available. Policies can layer across scopes;
an additional repository allowance does not remove an inherited restriction.
See [GitHub's policy administration guide](https://docs.github.com/en/actions/how-tos/administer/control-workflow-execution).

If the reviewed lifecycle still requires `pull_request_target`, configure an
applicable event policy for the **exact caller path** in that repository:

- For the packaged consumer example: `.github/workflows/changelog.yml`.
- For this repository's internal caller: `.github/workflows/repository-changelog.yml`.
- For a renamed or split caller: use its actual paths and declared events.

Allow all events that the selected caller needs, including `pull_request_target`.
The consumer example also declares `pull_request`, `push` and `workflow_dispatch`.
Restricting its allowance to only `pull_request_target` would block the other
routes. Keep unrelated workflow paths outside this policy's target. If actor
restrictions are also present, review the required contributor and automation
identities, including Dependabot and the configured App; do not silently broaden
them.

Configuring a policy in `php-fast-forward/changelog` does not configure consumers.
The consumer owns the event-triggering caller and its repository/organization
policy. Calling a reusable workflow does not transfer the provider repository's
permission settings.

The following is a **review example**, not an automatically applied setting. It
uses the [documented REST policy format](https://docs.github.com/en/rest/actions/policies#create-a-repository-actions-policy)
for the consumer caller. Adapt the path and event set to the actual file, and
review existing/inherited policies before any authorized change:

```json
{
  "name": "Changelog caller events",
  "enforcement": "active",
  "conditions": {
    "workflow_path": {
      "include": [".github/workflows/changelog.yml"],
      "exclude": []
    }
  },
  "rules": [
    {
      "type": "restrict_action_events",
      "parameters": {
        "allowed_events": [
          "pull_request",
          "pull_request_target",
          "push",
          "workflow_dispatch"
        ]
      }
    }
  ]
}
```

After an authorized configuration change, inspect an ordinary contribution, a
Dependabot PR and an approved merged version PR in Policy insights and their job
results. A successful preparation run alone does not prove that the separate
publication event is permitted. Use a disposable pilot and the selected release's
preview settings when real publication is not authorized.

## Read-Only Diagnosis

An authorized administrator can list repository policies, including applicable
parent policies, with the [Actions policies API](https://docs.github.com/en/rest/actions/policies#list-repository-actions-policies):

```sh
gh api --method GET \
  -H 'X-GitHub-Api-Version: 2026-03-10' \
  'repos/OWNER/REPOSITORY/actions/policies?has_parents=true&per_page=100' \
  --paginate
```

Inspect each returned policy's `enforcement`, source, workflow conditions and
event rule. The endpoint requires repository administration permission even for
this read. A denied request means the policy is unverified; do not interpret it
as an empty policy list or request broader credentials automatically. An empty
list of configured policies also does not prove that GitHub's built-in default
evaluation is absent: inspect the run's policy result and settings.

| Observation | Next step |
| --- | --- |
| Evaluate reports a restriction; jobs succeed | Review the caller and prepare the required policy before enforcement. |
| Active policy blocks the event before jobs start | Ask the responsible administrator to review that specific policy and caller. |
| Policy passes, but a job fails | Read the job diagnostic; investigate its token, inputs or operation separately. |
| Read-only checks pass, but comments or publication never run | Inspect the `pull_request_target` run and its policy result, not only the `pull_request` run. |

This library does not modify GitHub administration policies during installation
or execution. Keep the reviewed event split and privileged checks intact when
resolving the platform setting.
