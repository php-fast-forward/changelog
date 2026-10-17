---
name: fast-forward-changelog
description: Adopt Fast Forward Changelog GitHub automation, write and validate independent fragments, preview releases, and use its CLI or PHP services. Use for application changelog integration and approved release maintenance.
license: MIT
compatibility: Supports fast-forward/changelog ^1.0 (minimum 1.0.0). GitHub automation requires Linux with Docker, Bash and Git; no consumer PHP installation. Local CLI/PHP integration requires PHP ^8.5 and Composer. GitHub credentials are needed only for authorized remote operations.
metadata:
  author: php-fast-forward
  source: original
  repository: https://github.com/php-fast-forward/changelog
  api-baseline: fast-forward/changelog ^1.0 (minimum 1.0.0)
---

# Fast Forward Changelog

Adopt one GitHub lifecycle that checks contributions, maintains a PR comment,
authors verified Dependabot fragments and prepares one `changelog/version` PR.
Ordinary contributions add independent `.changelog/*.md` files. The version PR
consolidates them into the single `CHANGELOG.md` and deletes only the consumed
fragments. With a known GitHub repository, concrete version consolidation also
completes missing footer links: the oldest version's release/tag and comparisons
for subsequent versions. Existing references remain intact; unknown repositories
produce no invented destinations. Keep real release publication disabled during adoption.

Version automation also resolves missing fragment PR references from verified
Git history and merged-PR evidence by default. It enriches the rendered notes in
memory without adding metadata to the fragment or inventing an author or commit
link. Explicit metadata stays intact. No qualifying PR leaves the description
alone; failed or ambiguous evidence blocks preparation before writes.

Read applicable application instructions and Git status first. Preserve existing
work, repository policies, workflows, plugin choices and secrets. This skill
supports the `fast-forward/changelog ^1.0` product contract from `1.0.0`; earlier
versions are outside it. For CLI/API use, inspect
`composer show fast-forward/changelog` and `vendor/bin/changelog list --raw`.
A mismatch calls for compatible documentation or a separately authorized upgrade.
Installing this skill copies instructions; it does not install the runtime or
activate a host session.

## Start with GitHub automation

Create `.github/workflows/changelog.yml` in the application, using this caller.
Its `uses` points to a reviewed immutable implementation commit. Review the
selected revision and update the pin deliberately when adopting another version.
GitHub requires reusable implementations directly in `.github/workflows`;
`resources/workflows/changelog.yml` in the library is a copyable caller.

```yaml
name: Changelog
on:
  pull_request:
    branches: [main]
    types: [opened, synchronize, reopened, ready_for_review, labeled, unlabeled]
  pull_request_target:
    branches: [main]
    types: [opened, synchronize, reopened, ready_for_review, labeled, unlabeled, closed]
  push:
    branches: [main]
  workflow_dispatch:
permissions: {}
jobs:
  changelog:
    permissions:
      contents: write
      pull-requests: write
      issues: read
      statuses: write
    uses: php-fast-forward/changelog/.github/workflows/changelog.yml@4934f391eb6022814599fec86f003216ef512d79
    with:
      base-branch: main
      source: tags
      comment-mode: always
      prepare-release: true
      publish: false
```

Adjust both branch filters and `base-branch` to the release branch. Linux runners
need Docker, Bash and Git; the caller needs no PHP, Composer, Node installation or
custom shell script. Jobs use `ubuntu-latest` by default; optional `runs-on`
accepts JSON Linux runner labels. Validate runner-group access before selecting
an existing self-hosted runner. See the settings reference for all optional inputs.

Allow Actions to create PRs according to application policy. The default token
uses each job's narrowed permissions; PR-head checks remain read-only and trusted
base code handles privileged operations. `GITHUB_TOKEN` requires approval for
generated PR workflows. For automatic downstream CI, pair `app-client-id` with
`secrets.app-private-key` and set `automation-actor` to the App's Bot login.
Each trusted job creates its own scoped token; do not combine this pair with
the alternative `secrets.token`. Use existing secret storage, never embed keys
or tokens in YAML. Read the automation reference before changing Bot identity.
No checkout of untrusted PR code belongs in a privileged custom job.

Open a small pilot PR containing a fragment like the next example. Verify the
exact head's `Fast Forward Changelog` status, its sticky comment and independent
application checks. Exercise missing/malformed notes and a Dependabot PR, then
inspect the managed version PR: only central history and consumed deletions,
without technical plan files, generated comments or a new `Unreleased` section.
`prepare-release: false` limits an initial pilot to contribution feedback.
Require the exact status in branch protection with current-head checks.
The workflow never merges its version PR; `publish: false` remains in place until
publication is separately authorized for the approved consolidation.

## Record and check an ordinary contribution

Choose a unique lowercase dash-separated filename and create, for example,
`.changelog/preserve-markdown-spaces.md`:

```markdown
---
category: fixed
type: patch
---

Preserves meaningful Markdown spaces when parsing fragments.
```

Read the actual change before choosing its observable description. Preserve
Markdown bytes and existing notes. Do not invent a release date, issue/PR number,
author or security outcome. Optional `issue` and `pull_request` are positive
integers; `author` is an actual GitHub login. Omit unknown identities.

| Category | Default SemVer impact |
| --- | --- |
| `added`, `changed`, `deprecated` | `minor` |
| `removed` | `major` |
| `fixed`, `security` | `patch` |

Persist both `category` and effective `type`. Explicit `major`, `minor` or `patch`
can override the category default when justified, such as a compatible dependency
update using `changed`/`patch`. Unknown metadata, conflicting legacy fields,
nested/hidden fragments and symlinks fail. `.changelog/AGENTS.md` is reserved.

With an existing local CLI, the equivalent first-use commands are:

```sh
vendor/bin/changelog add "Preserves meaningful Markdown spaces when parsing fragments." --category=fixed --no-interaction
vendor/bin/changelog check --no-interaction
vendor/bin/changelog status --json --source=tags --no-interaction
```

Inspect the generated path: creation adds exactly one fragment without changing
central history. Empty local inventory is valid; inherited notes do not satisfy
a new contribution.

When the contribution's origin is already known, optionally record its verified number
with `add --pull-request=<number>` or `pull_request: <number>` in frontmatter.
Read the actual PR through the application's existing GitHub tooling, API or
browser. Confirm its repository, head branch and complete head SHA correspond
to this contribution, then take the positive number from that PR's canonical
URL or trusted metadata. An issue number, branch suffix or commit message is
not sufficient evidence.

With `PULL_REQUEST` set to that verified number, author the same contribution
with its link:

```sh
vendor/bin/changelog add "Preserves meaningful Markdown spaces when parsing fragments." --category=fixed --pull-request="$PULL_REQUEST" --no-interaction
```

If the PR number is unknown, keep the field absent. Contribution checks do not
require it, and opening a PR does not require a follow-up fragment edit or
commit. Unknown metadata must never block authoring or be filled with an
invented number.

The version workflow discovers eligible missing PR references after the
contribution is merged; ordinary fragments need no later edit. Its optional
`resolve-pull-requests` input defaults to `true` and must match across preparation,
ownership checks and publication. Set it to `false` consistently to keep only
explicit references. Local CLI commands keep discovery disabled unless
`--resolve-pull-requests` is selected. This is independent of `source: tags`:
tag-based historical imports do not suppress enabled PR-origin API reads.

Ordinary PRs cannot modify/delete inherited fragments or edit/rename central
history. Explicit human editorial maintenance can replace pending notes only
through the verified maintenance policy and committed summary proofs described
in the CLI reference; it grants no release or publication authority.
`status` is read-only. Successful commands exit `0`; invalid arguments
exit `2`, validation/operational failures exit `1`. Fix all diagnostics and rerun
the same check. Do not use an editable label as waiver authority.

After the intended contribution is committed under existing task authority,
compare its committed PR changes with a fetched, explicit baseline:

```sh
vendor/bin/changelog check --since=origin/main --no-interaction
```

This comparison reads the committed baseline-to-`HEAD` diff. An untracked or
uncommitted fragment is checked by local `check` but cannot satisfy this committed
contribution check. Without commit authority, leave the reviewed local fragment
and report that the PR comparison awaits its authorized commit.

## Select additional operations

- Load [the CLI procedure](references/cli.md) for all local commands, explicit
  fragment commits, release previews, history/templates, recovery or approved
  publication. `version --check` uses exit `1` for drift as well as failure;
  inspect its output. Dry runs establish no publication authority.
- Load [automation settings](references/automation.md) for monorepos, optional
  individual workflows, credentials, generated-head checks and pilot acceptance.
- Load [PHP integration](references/php-api.md) when an application needs injected
  services. It includes the complete [contribution program](examples/contribution.php)
  with an explicit consumer autoloader and working directory. PHP services are
  additional entrypoints. Keep the application's existing command-loader
  arrangement; use domain services without replacing it.

Do not treat an ordinary fragment task as authority to consolidate, commit, push,
merge, install elsewhere, publish tags/releases or change host configuration.
Retain authority already given in the current task. Publication uses the exact
approved merge SHA and verified committed transaction evidence; never infer
release facts from a moving branch, PR text or commit message alone.

Report changed paths, executed checks and exact observed Git/PR/release identities.
Keep file distribution, installation, native discovery, session activation and
successful model task execution as distinct observations.
