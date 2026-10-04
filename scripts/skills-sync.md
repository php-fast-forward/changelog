# skills-sync.mjs

Verify the real canonical `.github/skills/changelog` package and the repository
Codex/Claude directory links. The helper uses Node built-ins and requires
Node 22.20 or later. It never installs user skills or changes host configuration.

```sh
node scripts/skills-sync.mjs --check
node scripts/skills-sync.mjs --write
node --test scripts/skills-sync.test.mjs
```

Default/`--check` performs no writes. It verifies regular `SKILL.md` and `LICENSE`
resources, prints a JSON summary and exits 1 if a link needs creation/migration.
The two declared links are `.agents/skills/changelog` and
`.claude/skills/changelog`; each targets `../../.github/skills/changelog` relative
to its parent directory. Copilot's `.github` package remains a real directory.

`--write` creates missing links and migrates legacy real copies only when their
complete inventory and every byte match the canonical package. Both adapters
are preflighted before any change. Divergent bytes, unknown files/directories,
unexpected regular files and links to other targets are preserved and cause an
error. Existing correct links require no regeneration after a canonical edit.

Canonical resources and all parent paths must be regular, without symbolic
ancestors, within 8 directory levels and 200 resources. Only the two declared
adapter links may be symbolic. No recursive removal reaches an unknown target.
A write error can leave a partially migrated declared adapter; preserve the diff,
repair the reported condition and restore its exact previous package from Git
before retrying. Rollback restores the root instructions, package layout and
verifier together as one reviewed diff.

On Windows with `core.symlinks=false`, Git may materialize a tracked symlink as
an ordinary file containing its target. A read-only check accepts that fallback
only when the file bytes and the Git index's `120000` mode/blob prove the exact
expected link. JSON explicitly reports `materialized=false` and
`state=tracked-link-text`; this does not establish working Codex/Claude directory
links. The real Copilot package remains readable. `--write` preserves a verified
fallback; enabling native symlinks or choosing a separate authorized project
copy installation is an environment decision, not a helper side effect.

The repository root comes from the script location, independent of the caller's
directory. `--help` exits 0; invalid arguments, unsafe resources and drift exit 1.
Tests use disposable directories and isolated local Git metadata, with no real
HOME, credentials, network or user installation. Native symlink tests explicitly
skip when a Windows fixture lacks creation privileges; tracked-link fallback
fixtures still run. Package discovery and native host activation are separate
checks described in [skill distribution](../docs/skills.md).
