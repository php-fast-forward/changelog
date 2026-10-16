# Development scripts

These four PHP files are internal development gates. Consumers run
`bin/changelog` or the Composer binary; Docker actions call that CLI directly.
The production image does not copy this directory, and source archives exclude
it.

| File | Actual callers | Responsibility |
| --- | --- | --- |
| `lint.php` | `composer lint` → `quality` → `check` | Validate syntax of package-owned PHP files. |
| `php-files.php` | Syntax checker and quality fixtures | Share the bounded owned-file inventory, excluding vendor and unrelated paths. |
| `phpdoc.php` | `composer phpdoc` → `quality` → `check` | Require method summaries declared by AGENTS.md; semantic accuracy remains a review responsibility. |
| `verify-quality.php` | `composer quality:verify` → `quality` → `check` | Exercise isolated success/rejection cases so a broken gate cannot silently pass. |

There are no Node scripts, npm dependencies or skill-alias generation steps in
this directory. The former `skills-sync` helper and its tests existed only to
maintain the retired host links; they have no role after the published skill
moves to `resources/skills/fast-forward-changelog`.

Run `composer check` for these gates, style and unit coverage. The separate
`composer test:packaging` fixture validates actual consumer installations and
the distributed skill. Runtime behavior belongs in services and their tests,
never in these development helpers.
