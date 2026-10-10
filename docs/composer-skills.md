# Composer skill installation

The package declares `extra.skills.source: .github/skills` and includes the real
canonical skill in Composer archives. It keeps type `library`, its normal vendor
path and CLI binary. Installation remains a consumer choice: no skill installer
is a runtime dependency, and this package does not enable Composer plugins.

## Use llm/skills

In an authorized consumer, install the reviewed CLI package and prepare this
explicit `skills.json` before allowing/installing the optional plugin:

```json
{
  "target": ".github/skills",
  "aliases": [],
  "discovery": false,
  "auto-sync": false,
  "vendor-sources": false,
  "dependencies": {
    "composer": {
      "trusted": ["fast-forward/changelog"],
      "trusted-replace": true
    }
  }
}
```

```sh
composer config allow-plugins.llm/skills true
composer require --dev llm/skills:1.14.0 --no-scripts
composer skills:show fast-forward/changelog
composer skills:update fast-forward/changelog --dry-run
composer skills:update fast-forward/changelog
```

The last command copies `changelog/SKILL.md` and `LICENSE` into the configured
project target. `.github/skills/changelog` stays a real directory for Copilot.
Keep aliases empty when the consumer already owns other host directories.
Optional aliases require a reviewed target layout; this example preserves
existing `.agents` and `.claude` directories. Changes to copied managed resources
belong in their donor or a separately named consumer-owned skill.

Verified with `llm/skills` 1.14.0 in a disposable Composer consumer: install with
auto-sync disabled, read-only show and dry run created no skill files; explicit
update copied the exact canonical bytes; repeated update retained those bytes
and preserved an unrelated manual skill. This verifies materialization, not
native host activation. See the [tagged installer documentation](https://github.com/roxblnfk/skills/blob/1.14.0/README.md).

## Fast Forward installer alternative

[`fast-forward/composer-installers` 0.3.0](https://github.com/php-fast-forward/composer-installers/tree/v0.3.0)
materializes declared payloads for `fast-forward-resource-bundle` packages.
A disposable bundle containing the canonical skill was installed successfully
into `.github/skills` with mutable policy and an explicit `installer-paths` map;
the files remained real and an unrelated manual skill was preserved.

The current library is not that bundle type. Changing its type would make a
consumer with the Fast Forward plugin require a destination map even when that
consumer only wants the CLI. A separately distributed skill bundle can use that
installer without changing the CLI package's installation contract. The validated
Fast Forward fixture used a synthetic companion bundle, not a published package.
