# Set up a project with CPX

The `install` command configures GitHub automation and offers the optional agent
skill. It previews the complete caller before asking to write it. Existing
workflows, their selected versions and locally edited skills are preserved.

Use PHP `^8.5`, Git and [CPX](https://github.com/laravel/cpx). Run from an existing
Git repository; CPX keeps the CLI dependencies outside the application. No
`composer require` or Composer post-install hook is needed.

## Choose the release and inspect help

Use the stable CLI through CPX and inspect its help before setup. The installer
independently discovers and previews a published, pinned workflow reference:

```sh
cpx --skip-local fast-forward/changelog help install
cpx --skip-local fast-forward/changelog install
```

If the selected release does not offer `install`, follow the
[manual caller setup](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/README.md)
using the resources and documentation from that release. Do not substitute
development code implicitly.

`--skip-local` selects CPX's separate tool installation. The command operates on
the current project's Git root, not CPX's package cache. Use
`--cwd=/path/to/project` to select another existing checkout.

## Answer the questions and review the result

For a repository without changelog automation, setup asks:

1. **Release branch.** The suggestion comes from local `origin/HEAD`, or `main`
   with a diagnostic when that ref is unavailable. Nothing is fetched to infer it.
2. **Automatic publication.** The default is yes: after you review and merge a
   version PR, the workflow creates its approved tag and GitHub Release. The
   workflow does not merge that PR. Choose no for a deliberate validation-only
   pilot; consult that release's publication and recovery behavior before merging.
3. **Agent skill.** The default is yes, using
   `.agents/skills/fast-forward-changelog`. This copies instructions and records
   their provenance; it does not activate an agent session.

An optional advanced section asks about the managed version-PR branch, Linux
runner labels, PR comment mode and version-PR title. Blank answers retain the
selected workflow's defaults. The default title identifies the GitHub repository
and transition, such as `chore(release): acme/example v1.2.0 → v1.3.0`.
Use a literal package label in a custom template when preferred; see
[title customization](version-pull-request.md).
Inspect [lifecycle settings](reusable-lifecycle.md) for their meaning, and that
published revision for its exact supported inputs.

Setup renders `.github/workflows/changelog.yml` with the release's verified
commit SHA in `uses` and its full version in a readable comment. It sets event
branch filters and only input values that differ from the verified defaults.
Review the complete YAML and skill state, then approve the final confirmation.
The command rechecks the proposed workflow before applying it; a changed plan,
including a release tag retargeted to another commit, blocks writes. Later tag
changes cannot change the commit selected by the installed caller. No technical
plan file is added to the repository.

Event branch filters escape [GitHub pattern operators](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#filter-pattern-cheat-sheet)
so names such as `release/1.2+` match literally; the reusable workflow's
`base-branch` input retains the original branch name.

Installation creates local files only. It does not commit, push, change GitHub
settings, run workflows or publish a tag/release. Review the diff and configure
any required [GitHub App credentials](github-app.md) through GitHub's secret
storage; setup does not collect or save secrets. Then commit the integration
through the project's normal review process and validate a contribution PR.

## Preview or automate setup

Preview both components without creating directories, acquiring installation
locks or writing files:

```sh
cpx --skip-local fast-forward/changelog install \
  --dry-run --json
```

Apply deliberate unattended choices:

```sh
cpx --skip-local fast-forward/changelog install \
  --yes --no-interaction --base-branch=main --publish=true --no-skill \
  --set=comment-mode=on-failure
```

`--yes` accepts the plan; it never permits overwriting existing files.
Without `--yes`, noninteractive application exits `2` without writes. JSON mode
never asks questions: use it with `--dry-run` for inspection or `--yes` to apply.
It emits the structured result on stdout and JSON errors on stderr.

| Option | Default | Purpose |
| --- | --- | --- |
| `--cwd`, `-C` | `.` | Existing consumer Git checkout; operates from its repository root. |
| `--ref`, `-r` | Executing package's stable version | Select a published full release tag, such as `vX.Y.Z`; the new caller uses its verified commit SHA. Required from a development runtime. |
| `--base-branch` | Local `origin/HEAD`, otherwise `main` | Release branch and caller event filters. |
| `--publish=true\|false` | `true` | Configure publication after the reviewed version PR is merged. |
| `--no-skill` | Off | Skip native skill installation and preserve any existing skill owner. |
| `--set=name=value` | No overrides | Repeat for supported reusable inputs; duplicate names are rejected. |
| `--yes`, `-y` | Off | Apply without questions or a final confirmation prompt. |
| `--dry-run`, `-d` | Off | Return the proposed result without local mutation. |
| `--json`, `-j` | Off | Structured output without prompts. |

`--set` converts the exact values `true` and `false` to booleans; other values
remain literal strings. Names and types must match the selected workflow's
contract. Expressions such as `${{ ... }}` are rejected. Values equal to declared
defaults are omitted from YAML; event branch filters still reflect the chosen
release branch. `--base-branch` and `--publish` take precedence over the same
names supplied with `--set`.

For a missing caller, the selected workflow version defaults to the executing
package's stable version. `--ref` selects another published full tag without
changing the executing CLI or its bundled skill. The result's
`workflow.reference` records this release tag; the caller's `uses` selects its
verified commit. Moving channels, development refs and prereleases are rejected
as installation evidence. Setup verifies a
published stable GitHub Release, its tag/commit and both YAML blobs from the
canonical public repository. These read-only public API requests use no consumer
credentials, with a 10-second timeout and 30-second maximum duration per request;
this is not an overall command deadline.

For a caller following the published `v0` channel, use the
[manual consumer example](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/README.md).
Verify the channel's current published commit and supported inputs, then keep
`@v0` in `uses` when that is the owner's chosen policy. The guided installer's
fixed-commit output and its `--ref` validation do not change this manual option.

## Preserve existing integrations

One recognized Fast Forward lifecycle caller is kept byte-for-byte, including
its pin, inputs and custom filename. Existing modular workflows and individual
Actions at the same reviewed reference are also preserved and listed in the
diagnostics. Installation is not an upgrade or migration command; workflow
selection options do not rewrite those files. It can still offer an absent
project skill.

Multiple full lifecycle callers, inconsistent modular references, competing
release automation, malformed YAML or an unrelated
workflow occupying `.github/workflows/changelog.yml` require manual review.
Setup reports `blocked` and does not replace them. There is no `--force` route.
Unrelated valid CI workflows are left intact.

The native receipt records the installed skill's package version, optional source
commit and per-file hashes. `installed_source` describes a verified intact native
copy; `source` describes the payload bundled with this CLI. They can name
different package versions while identical instructions remain current. This is
local integrity evidence, not a signature or a lookup of the latest remote skill.

Native copies that are older or different are preserved; use
[`skill:install --check` and explicit `--update`](skills.md) to review changes.
Locally modified, foreign or recovery-pending copies block native installation.
An NPX `skills-lock.json` entry for this skill is preserved, including when the
installed directory is missing. Use `--no-skill` to configure only the workflow
and retain NPX ownership. The native receipt and NPX lock are independent formats;
setup neither writes an NPX lock nor claims bidirectional compatibility.

| Result | Meaning and next step |
| --- | --- |
| `planned` | Read-only preview of a new caller. Review its bytes and choices. |
| `installed` | The new caller was created; inspect the independent skill result too. |
| `existing` | The recognized caller was preserved. |
| `confirmation-required` | No application was authorized; review and rerun with confirmation. |
| `blocked` | Resolve the reported conflict or ownership issue before retrying. |
| `partial` | The workflow was created, but skill installation failed. Preserve it, inspect diagnostics and repair only the skill. |

Exit `0` means a permitted preview or completed operation, `2` means invalid
input or missing confirmation, and `1` means a blocked, partial or operational
failure. A partial installation does not roll back or overwrite the new workflow.

---

[Adoption](adoption.md) · [Skill management](skills.md) · [Documentation index](index.md)
