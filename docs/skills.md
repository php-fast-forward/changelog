# Install the Fast Forward Changelog skill

Use this guide to install and maintain the `fast-forward-changelog` instructions.
The skill teaches the contribution flow and consults the selected release's help
and documentation for command and workflow details. Installing instructions does
not activate an agent session. [CPX executes the CLI](adoption.md); PHP service
integration uses the application's Composer installation.

## Install with the Changelog CLI

Select a published release whose command list contains `skill:install` and set
`CHANGELOG_VERSION` to that exact numeric version. Release `0.1.0` does not
include this command; use the GitHub route below when your selected release does
not offer it. Verify that release's PHP requirements and inspect its help:

```sh
cpx --skip-local "fast-forward/changelog:$CHANGELOG_VERSION" help skill:install
cpx --skip-local "fast-forward/changelog:$CHANGELOG_VERSION" skill:install
```

This route needs no Node.js or dependency in the application. It copies the
complete skill bundled with that CLI into
`.agents/skills/fast-forward-changelog`. The copy includes a native
`.fast-forward-install.json` receipt with package provenance and file hashes;
its relative target remains portable when the project is cloned elsewhere.
It creates no host aliases and executes no bundled example.

Use the same command for deliberate management; help owns the full option contract:

| Mode | Behavior |
| --- | --- |
| Default | Install an absent target; an identical native copy needs no write. |
| `--check` | Compare local ownership and bytes with this CLI's bundled payload, without writes or network. |
| `--update` | Replace an intact native copy explicitly. Local edits, extra files, unknown owners and known downgrades are refused. |

`--check` and `--update` are exclusive. `--dry-run` previews without writes.
Project scope is the default; `--cwd` selects the project, `--target` selects a
relative discovery root and `--global` explicitly selects the configured home.
A later CLI release carrying identical skill bytes does not require reinstalling.

On human command paths, the CLI can warn when an intact native copy at the default
project target is provably older than its bundled instructions. This is a local comparison, not a registry
lookup. Missing, foreign, modified or ambiguous copies do not generate that
automatic warning; inspect them with `--check`. JSON, pipelines and CI retain
their ordinary output. No warning installs or updates anything.
Check custom or global targets explicitly with their original scope options.

Interrupted updates retain staging/backup evidence outside discovery and refuse
another replacement until inspected. Follow the reported paths and preserve
both copies; do not delete recovery data to force a retry.

Choose one owner for the installed copy. This receipt is not the NPX Skills
lock or `llm/skills` configuration, and the command does not adopt their copies.
Use their own update/removal route, or explicitly archive a selected copy before
migrating installers. After installation inspect the files, refresh the host
session and verify discovery separately.

## Install directly from GitHub

This alternative needs Node.js `22.20.0+`, npm and Git. It does not
require PHP, Composer or a prior installation of the Changelog library. From
the consumer project, install only this skill for the host you use:

```sh
npx skills add php-fast-forward/changelog --skill fast-forward-changelog
```

The repository shorthand resolves its default branch and discovers
`resources/skills/fast-forward-changelog`; `--skill` selects that one public name.
The installer detects the current agent or asks for the host
and installation method. Project scope is the default; Codex uses
`.agents/skills/fast-forward-changelog`.

Review and commit the project copy and the installer's lockfile under the
application's policy. Inspect the complete license, references and example after
copying; refresh the agent session and verify that it loads the public name.
A successful copy or listing does not establish native activation or task quality.

The [Skills CLI source documentation](https://github.com/vercel-labs/skills)
describes direct repository paths, scoped selection and copy mode. To verify
an updated local source before publishing its revision, the same installer can
select a reviewed checkout directly:

```sh
npx skills add /path/to/changelog/resources/skills/fast-forward-changelog --skill fast-forward-changelog
```

For an existing Composer installation, the source may instead be
`./vendor/fast-forward/changelog/resources/skills/fast-forward-changelog`.
That is an alternative donor location, not an installation prerequisite.

## Advanced installation choices

### Select a revision, host or global scope

The documented route was checked with Skills CLI `1.7.2`; its Node requirement
is `22.20.0+`. To select an immutable source, choose a reviewed commit whose
skill metadata supports the installed runtime. Set `CHANGELOG_SKILL_REVISION`
to that full commit SHA before running this variant; older development commits
may declare a different product contract. It also selects Codex and copy mode
without the installer's prompts:

```sh
npx --yes skills add \
  "https://github.com/php-fast-forward/changelog/tree/${CHANGELOG_SKILL_REVISION:?Set the reviewed full commit SHA}/resources/skills/fast-forward-changelog" \
  --skill fast-forward-changelog --agent codex --copy --yes
npx skills list --agent codex
```

Choose the actual host, such as `claude-code`. `--copy` installs real files rather
than host-directory symlinks. Add `--global` explicitly to installation and listing
only when global scope is intended. Installing the Composer plugin globally does
not select a global host target.

### Optional Composer synchronization

The [Composer installation guide](composer-skills.md) documents the optional
`llm/skills ^1.15` project route, narrow donor trust, first synchronization,
updates and removal limits. It is development tooling and needs the consumer's
plugin approval. A Composer suggestion is not an installation or approval.

### Guarded manual copy

Prefer project scope for a team. From an authorized consumer, copy the whole
package from a reviewed checkout and refuse an existing destination,
including a dangling symlink:

The canonical source is `resources/skills/fast-forward-changelog`, including its
license, references and example. Preserve the complete flat directory: its name
matches the public skill name. `.agents/skills` is consumer-owned; the package
creates no host aliases or copies automatically.

```sh
skill_source="/path/to/changelog/resources/skills/fast-forward-changelog"
skill_target=".agents/skills/fast-forward-changelog"
test -f "$skill_source/SKILL.md" &&
test ! -e "$skill_target" &&
test ! -L "$skill_target" &&
mkdir -p "$(dirname "$skill_target")" &&
cp -R "$skill_source" "$skill_target"
```

For explicitly selected global Codex scope set
`skill_target="$HOME/.agents/skills/fast-forward-changelog"`. The
[Codex local-skill locations](https://learn.chatgpt.com/docs/build-skills#where-codex-loads-local-skills)
document `$HOME/.agents/skills` as user scope. A Claude Code
consumer can choose `.claude/skills/fast-forward-changelog` or its home
counterpart. Verify the actual host's configured discovery paths and avoid
ambiguous duplicate copies. Copy real files rather than linking a discovery
root into replaceable Composer `vendor/` content. A missing donor source calls
for inspecting the installed version/archive, not creating an empty placeholder.

Before an authorized update or removal, archive only that selected package
outside every discovery root. These guards also reject symlink destinations:

```sh
skill_backup="$HOME/.agent-skill-backups/fast-forward-changelog-$(date +%Y%m%d%H%M%S)-$$"
test -d "$skill_target" &&
test ! -L "$skill_target" &&
test ! -e "$skill_backup" &&
test ! -L "$skill_backup" &&
mkdir -p "$(dirname "$skill_backup")" &&
mv "$skill_target" "$skill_backup"
```

Moving out removes the manual copy from discovery. Review the new donor and
repeat the guarded copy to update. For rollback archive any replacement first,
restore the complete saved directory to its original target and refresh the
session. Preserve unrelated skill directories. Plugin-managed copies may return
on the next sync until the donor/trust policy changes.

## Update and remove a Skills CLI copy

Record the product revision and installer version. Review the new donor, archive
local changes outside discovery and repeat the selected installation route.
Use the immutable-source variant when the exact revision must remain fixed.
Removal selects only the intended name and scope:

```sh
npx --yes skills remove fast-forward-changelog --agent codex --yes
# Explicit global alternative:
npx --yes skills remove fast-forward-changelog --agent codex --global --yes
```

After any route inspect all copied files, refresh the host session, confirm it
loads `fast-forward-changelog` and perform a bounded contribution task. File
copies and installer listings do not establish native activation or task quality.

## Distribution verification

Run `composer test:packaging` for archive or payload changes. The fixture compares
every skill file, executes its PHP example and checks contribution arguments
through the installed no-dev CLI. Those runtime checks do not invoke CPX.
`.gitattributes` excludes repository agent configuration, development
scripts, tests and docs from archives; source clones have different contents.
Official [Agent Skills format validation](https://agentskills.io/specification#validation)
and [Skills CLI](https://github.com/vercel-labs/skills) documentation supplement
these checks. Native host acceptance remains a separate consumer operation.

---

[Documentation index](index.md)
