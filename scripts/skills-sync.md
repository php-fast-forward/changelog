# skills-sync.mjs

Generate real Claude/Copilot skill copies from `.agents/skills/changelog`.
Edit the canonical package only; generated adapters retain exact bytes and MIT
license. The helper uses Node built-ins and requires Node 22.20 or later.

```sh
node scripts/skills-sync.mjs --check
node scripts/skills-sync.mjs --write
node --test scripts/skills-sync.test.mjs
```

Default/`--check` compares every canonical resource without writing. It prints a
bounded JSON summary and exits 1 on drift. `--write` updates only changed files
in `.claude/skills/changelog` and `.github/skills/changelog`; a second run reports
no changes. `--help` exits 0, invalid arguments or unsafe resources exit 1 with
a diagnostic on stderr. The repository root is derived from the script location,
so it does not depend on the caller's directory or scan user installations.

The canonical package requires regular `SKILL.md` and `LICENSE` files. All
resources must be regular files/directories, without symbolic ancestors, within
8 directory levels and 200 files. Both adapters are preflighted before writes.
Unknown adapter files cause a failure and are preserved; review them explicitly
instead of silently deleting them. Write failures may leave some copies updated;
repair the reported filesystem condition and rerun `--write`, then `--check`.
No host links, user configuration, runtime installation or network is touched.

Tests use disposable directories and synthetic package bytes to exercise normal
generation, no-write drift, repeatability, resources, rejected arguments and
unsafe paths. Rollback is the reviewed repository diff; the helper creates no
external snapshots or installation state.
