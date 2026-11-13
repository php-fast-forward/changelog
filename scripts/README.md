# Development scripts

These four PHP files are internal development gates. Consumers run
the Composer binary; contributors use `php bin/changelog` after installing dev
dependencies. Docker actions execute the compiled CLI.
The production image does not copy this directory, and source archives exclude
it.

| File | Actual callers | Responsibility |
| --- | --- | --- |
| `lint.php` | `composer lint` → `quality` → `check` | Validate syntax of package-owned PHP files. |
| `php-files.php` | Syntax checker and quality fixtures | Share the bounded owned-file inventory, excluding vendor and unrelated paths. |
| `phpdoc.php` | `composer phpdoc` → `quality` → `check` | Use the PHP AST to require method summaries, exactly one described tag per declared parameter, and described returns for non-constructor methods; constructors cannot declare a return tag. Semantic accuracy remains a review responsibility. |
| `verify-quality.php` | `composer quality:verify` → `quality` → `check` | Exercise isolated success/rejection cases so a broken gate cannot silently pass. |

There are no Node scripts, npm dependencies or skill-alias generation steps in
this directory. The former `skills-sync` helper and its tests existed only to
maintain the retired host links; they have no role after the published skill
moves to `resources/skills/fast-forward-changelog`.

Run `composer check` for these gates, style and unit coverage. The separate
`composer test:packaging` fixture validates actual consumer installations and
the distributed skill. Runtime behavior belongs in services and their tests,
never in these development helpers.

## PHAR staging

`phar/prepare.php NEW_RUNTIME_DIRECTORY` is the CI build preparation, following
`extra.phar.runtime-packages`. It copies runtime source/resources, restores the
private Composer manifest/autoloader and resolves exact locked package records
with Packagist, plugins and scripts disabled. Box remains the isolated compiler.
This helper is not exported to or executed by consumers. See [PHAR distribution](../docs/phar.md).
