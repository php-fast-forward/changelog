# Pull-request and Dependabot policies

The base workflow owns the trusted configuration, the checked-out runtime and
metadata collection. It MUST NOT execute PHP, Composer, scripts or workflow
code from an untrusted pull-request head. API reads inspect that head as data.
Neither PR prose nor a public CLI flag can waive the fragment requirement or
authorize direct changes to the central changelog.

## Human exceptions

`PullRequestPolicyInterface::inspect()` returns `PullRequestAuthorization` with
`waiverAuthorized`, `centralChangeAuthorized`, `kind`, `diagnostics` and
`headSha`. The caller MUST compare `headSha` with the exact head it checks before
using either authorization. An authorization for another PR or head cannot be
reused as a skip flag.

`changelog-not-required` waives the public fragment requirement only when the
label is present on the live PR and its latest labeled/unlabeled timeline event
is a grant by a GitHub `User`. `changelog-maintenance` applies the same rule to
central-history edits. A later removal or an unprivileged regrant revokes the
exception. The granting account's numeric ID and login must match a current
collaborator-permission response. Only the explicit effective `role_name`
`maintain` with `permission: write`, or `admin` with `permission: admin`, grants
authority. Ordinary `write`, unknown/custom role names, inaccessible permission
records and Bot label actors do not grant an exception. Maintainers may grant
an exception to a fork PR; this does not authorize privileged writes to the fork.

GitHub maps the maintain role to the legacy permission value write, so the
permission value alone cannot distinguish these roles. These rules use the
[collaborator permission API](https://docs.github.com/en/rest/collaborators/collaborators#get-repository-permissions-for-a-user)
and [issue timeline API](https://docs.github.com/en/rest/issues/timeline#list-timeline-events-for-an-issue).
Authority is checked live, including permissions revoked after the label grant.

## Generated version pull requests

The configured managed branch defaults to `changelog/version`; the configured
creator defaults to `github-actions[bot]`. Automatic central-history authority
requires all of the following:

- The PR targets the configured repository and its head belongs to the same
  repository, on the exact managed branch.
- The PR creator is the configured Bot account. An account lookup confirms its
  immutable numeric ID, login and Bot type.
- The head commit's actual GitHub author account matches that Bot. Its committer
  is either the same Bot or GitHub's verified `web-flow` account. Both routes
  require REST commit verification `verified: true` with `reason: valid`.
  The platform-committer route additionally confirms the immutable `web-flow`
  account and a GraphQL signature bound to the exact commit, with `isValid: true`,
  `state: VALID` and `wasSignedByGitHub: true`. Other human committers fail closed.
  Free-form Git author names/emails do not prove identity.
- The validated `release-plan.json` receipt is read at the head SHA. Its
  repository, paths, locale, template and tag prefix match configured options.
  Its saved base must be a GitHub-proven ancestor of the current PR base and
  head, allowing the same trusted PR to update after the base advances. The commit contains the exact footer
  `Changelog-Plan: <receipt-id>`.
- The central changelog read at that same SHA equals the receipt's complete
  contents and SHA-256 hash. Comparison scope contains only central/receipt
  changes and exact removals of consumed fragments; unknown files, renames,
  mismatched base hashes or potentially truncated comparisons fail closed.
  The comparison file limit is conservatively enforced below 300 files.

The footer and receipt are evidence of the generated transaction, not identity
credentials. A contributor copying them does not gain authority. Unsigned or
inconsistent Bot commits fail closed and require a verified human maintenance
grant. Writers creating commits through the GitHub API must authenticate as
the intended App/Bot and avoid custom author, committer or signature fields so
GitHub can provide Bot signature verification. See
[commit verification](https://docs.github.com/en/rest/commits/commits) and
[GitHub Bot signatures](https://docs.github.com/en/authentication/managing-commit-signature-verification/about-commit-signature-verification#signature-verification-for-bots).

## Dependabot fragments

`DependabotInputFactoryInterface::create()` validates a positive PR number, a
complete expected head SHA, package identifiers, dependency scope, ecosystem
and optional security alert numbers. It sorts and deduplicates names and alert
numbers. This input must be collected by trusted base code using verified,
immutable-pinned metadata tooling. The service revalidates the input before any
privileged API operation. PR title/body and arbitrary head files are not metadata
authority.

`DependabotFragmentServiceInterface::synchronize()` verifies the live PR creator
is exactly the GitHub `dependabot[bot]` Bot account, confirms the numeric account
ID with GitHub, and requires an open same-repository PR at the supplied head.
Fork writes, the base branch itself and stale heads are refused. Development
dependencies and GitHub Actions are included by default. `includeDev: false`
filters known direct development dependencies; ambiguous indirect scope is
refused rather than guessed. `includeActions: false` filters GitHub Actions.

The deterministic filename is `<fragment-directory>/dependabot-<PR-number>.md`.
The configured directory must be a validated repository-relative path; a monorepo
can use `packages/lib/.changelog`, and a custom fragment directory is supported. A normal dependency update uses
`category: changed` and explicit `type: patch`. The description contains sorted
package names and the fragment links to the PR/Dependabot author through the
same core changeset factory and renderer used by consumer code. Mutable PR
titles, rebase timestamps and version prose do not affect fragment bytes.

Security classification requires trusted metadata associating specific GitHub
alert numbers with this PR, followed by fresh alert API verification: every
alert must currently be open, match an updated package and ecosystem, and carry
a GitHub advisory ID. Closed/fixed/dismissed alerts, mismatches and unreadable
alerts are refused; permission/network errors never silently become ordinary
updates. The service does not infer vulnerable version ranges or associate an
unrelated same-package alert with the PR. That association belongs to the
trusted metadata collector. The official metadata action exposes advisory and
alert-state fields but not an alert number, so the collector must resolve and
verify that association before supplying alert IDs. See the
[official metadata action contract](https://github.com/dependabot/fetch-metadata/blob/main/action.yml)
and [individual alert API](https://docs.github.com/en/rest/dependabot/alerts#get-a-dependabot-alert).

Only the missing deterministic path is created. Identical bytes return
`unchanged` without a second commit; any differing, malformed or manual file
returns `refused` and preserves its contents. A generated marker or checksum is
not sufficient authority to overwrite a later human edit. Changed package or
security metadata may therefore require a human update to an existing fragment.

The service reads the path at the supplied head SHA and rechecks PR head,
branch and creator immediately before a create-only Contents request. It does
not supply the existing-file `sha` parameter. A file that appears during a race
cannot be overwritten by this create request. The Contents API uses the file
blob SHA for updates; it does **not** provide atomic compare-and-swap on a
branch head. A successful response must confirm a single parent equal to the
expected head. If a race is detected after creation, `conflict` includes the
created commit SHA and asks for review; transport uncertainty after a write
attempt also reports `conflict`. The caller must inspect the branch before a
retry and cannot assume that a write did not occur. No automatic rollback
rewrites another actor's commits. See the
[Contents API contract](https://docs.github.com/en/rest/repos/contents#create-or-update-file-contents).

## Runtime permissions and outcomes

Read-only PR policy evaluation needs PR/issue timeline, account, contents and
collaborator metadata access. Security evaluation additionally needs Dependabot
alert read access. Fragment creation needs repository contents write permission.
Dependabot-triggered workflows have special token/secret restrictions; use an
explicit trusted workflow/token boundary rather than running head code with
write privileges. See
[Dependabot Actions restrictions](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-on-actions)
and [GitHub's Dependabot automation guidance](https://docs.github.com/en/code-security/tutorials/secure-your-dependencies/automate-dependabot-with-actions).

Results use `created`, `unchanged`, `filtered`, `refused` or `conflict`. Callers
must surface diagnostics and stop privileged follow-up on `refused`/`conflict`.
API failures retain controlled diagnostic text and withhold raw server details
and credentials. Unit tests replace API, receipt, metadata, changeset rendering
and result boundaries; they never mutate a live GitHub repository.
