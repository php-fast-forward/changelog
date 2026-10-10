# Development scripts

These files maintain the repository. Consumers run `bin/changelog` or the
installed Composer binary; the Docker actions call that CLI directly. No script
in this directory creates contributions, consolidates releases or publishes them.
The production image copies `bin`, `src`, metadata and production `vendor` only.
The source archive excludes this development directory.

## What each file does

| File | Used by | Purpose |
| --- | --- | --- |
| `lint.php` | `composer lint`, `composer quality`, `composer check` | Runs PHP syntax validation for package-owned PHP files. |
| `php-files.php` | The syntax checker and quality fixtures | Lists the bounded owned paths, excluding `vendor` and unrelated files. It is a shared helper. |
| `phpdoc.php` | `composer phpdoc`, `composer quality`, `composer check` | Enforces explanatory method summaries required by AGENTS.md. Semantic accuracy still needs review. |
| `verify-quality.php` | `composer quality:verify`, `composer quality`, `composer check` | Exercises development gates with isolated success and rejection fixtures so a broken checker cannot report a false pass. |
| `skills-sync.mjs` | The repository review procedure and Tests workflow | Checks the real `.github/skills/changelog` package and its two exact Codex/Claude links; explicit `--write` performs bounded migration. |
| `skills-sync.test.mjs` | Tests workflow and local `node --test` | Verifies drift, preservation of manual files, idempotency and Windows tracked-link fallback using disposable fixtures. |

There are two Node files and no npm dependency installation. They use Node
built-ins and require Node 22.20 or later. Node is a development prerequisite for
the skill checks currently required by CI, not a runtime prerequisite for the
PHP library, CLI, container or reusable changelog workflows.

The link verifier protects the canonical skill and host compatibility rather
than the changelog domain. Existing correct links need no regeneration after a
canonical edit. Removing this check would remove verification of that shipped
layout. Its implementation language could be changed to PHP independently of
the CLI; such a migration must retain the tested Windows and file-preservation
behavior. This audit keeps the used helper and its tests.

## Run and recover

Use `composer check` for PHP quality and coverage. For repository skill checks:

```sh
node scripts/skills-sync.mjs --check
node --test scripts/skills-sync.test.mjs
```

Both commands use read-only checks or disposable test directories. Run the
verifier's `--write` mode only for an authorized repository layout change, after
reading [its operation and recovery guide](skills-sync.md). It never installs
skills into a user's environment. Preserve divergent or unknown resources and
repair the reported condition rather than removing them to force success.
