# Install the Fast Forward Changelog skill

The bundled `fast-forward-changelog` skill supports
`fast-forward/changelog ^1.0` (minimum `1.0.0`). Inspect the installed version
before using its CLI/API contract. Earlier versions require compatible
instructions or a separately authorized upgrade. Its canonical source is
`resources/skills/fast-forward-changelog`, including its MIT license, references
and executable example. Composer archives ship that complete payload.

Shipping files, copying into a discovery root, loading in a refreshed host
session and completing a task are different operations. Installing the skill
does not install the PHP CLI. `.agents/skills` remains a consumer-owned location;
this library creates no host aliases or project/global copies automatically.
Use the flat `fast-forward-changelog` directory: the public name must match its
immediate parent, without category nesting or a `php-` prefix.

## Optional project synchronization

The [Composer installation guide](composer-skills.md) documents the optional
`llm/skills ^1.15` project route, narrow donor trust, first synchronization,
updates and removal limits. It is development tooling and needs the consumer's
plugin approval. A Composer suggestion is not an installation or approval.

## Guarded manual copy

Prefer project scope for a team. From an authorized consumer with the product
already installed, copy the whole package and refuse an existing destination,
including a dangling symlink:

```sh
skill_source="vendor/fast-forward/changelog/resources/skills/fast-forward-changelog"
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

## Optional pinned Skills CLI

With Node.js `22.20.0+`, npm and authorization to copy project skills:

```sh
npx --yes skills@1.7.2 add ./vendor/fast-forward/changelog/resources/skills/fast-forward-changelog --skill fast-forward-changelog --agent codex --copy --yes
npx --yes skills@1.7.2 list --agent codex
```

Select only the host actually used, for example `claude-code`. `--copy` avoids
vendor symlinks. Add `--global` explicitly to both commands for global scope;
installing the Composer plugin globally does not select a global host target.
Record the product revision and installer version. Review the new donor, archive
local changes and repeat against that selected source to update. Commit intended
project copies/CLI lockfiles according to application policy. Removal selects
only the intended name/scope:

```sh
npx --yes skills@1.7.2 remove fast-forward-changelog --agent codex --yes
# Explicit global alternative:
npx --yes skills@1.7.2 remove fast-forward-changelog --agent codex --global --yes
```

After any route inspect all copied files, refresh the host session, confirm it
loads `fast-forward-changelog` and perform a bounded contribution task. File
copies and installer listings do not establish native activation or task quality.

## Distribution verification

Run `composer test:packaging` for archive or payload changes. The fixture compares
every skill file and executes its PHP example against an installed no-dev
consumer. `.gitattributes` excludes repository agent configuration, development
scripts, tests and docs from archives; source clones have different contents.
Official [Agent Skills format validation](https://agentskills.io/specification#validation)
and [Skills CLI](https://github.com/vercel-labs/skills) documentation supplement
these checks. Native host acceptance remains a separate consumer operation.
