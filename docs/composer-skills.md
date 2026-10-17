# Optional Composer skill synchronization

`fast-forward/changelog ^1.0` ships the complete
`resources/skills/fast-forward-changelog` package. It declares
`extra.skills.source: resources/skills` and suggests `llm/skills` while retaining
its `library` type, normal vendor path and CLI binary. The suggestion does not
install or approve a plugin. The optional consumer tool is `llm/skills ^1.15`;
keep it out of production runtime requirements.

## Prepare narrow project trust before first sync

Install the product at the separately selected compatible release/reviewed
revision. In an authorized consumer prepare `skills.json` **before** enabling or
installing the plugin:

```json
{
  "target": ".agents/skills",
  "aliases": [],
  "discovery": false,
  "auto-sync": true,
  "vendor-sources": false,
  "dependencies": {
    "composer": {
      "trusted": ["fast-forward/changelog"],
      "trusted-replace": true
    }
  }
}
```

Merge these values with existing configuration; preserve intentional aliases,
other dependencies and plugin approvals. The target is project scope by default.
`trusted-replace` replaces implicit dependency trust; `discovery:false` and
`vendor-sources:false` limit sources. Trusting `fast-forward/*` is a deliberate
broader choice. `aliases:[]` requests no extra host-directory links; a consumer
may select `.claude/skills` directly when that is its verified discovery root.

With existing authority to install and execute this Composer plugin:

```sh
composer config allow-plugins.llm/skills true
composer require --dev 'llm/skills:^1.15'
composer skills:show
```

Root plugin approval executes third-party code; the donor cannot grant it for
the application. Noninteractive approval must already be explicit. Normal
approved install/update then synchronizes trusted installed donors without an
extra post-install hook. Inspect the report and compare the complete target
`.agents/skills/fast-forward-changelog` to its donor. Commit intended manifest,
lockfile and `skills.json` changes according to the consumer's policy.

## Explicit mode and suppression

For deliberate manual sync set `auto-sync:false` before plugin installation.
Then inspect and apply only the selected donor:

```sh
composer skills:show
composer skills:update fast-forward/changelog --dry-run
composer skills:update fast-forward/changelog
```

A named package argument intentionally selects/trusts it for that run. It does
not test the unfiltered automatic trust policy. For Composer `2.10.3` with
`llm/skills 1.15.0`, `--no-scripts` alone still allows automatic synchronization.
Use `auto-sync:false` to disable automatic copies; `--no-plugins` prevents plugin
loading. Recheck behavior when changing those versions. A green Composer exit
alone is not evidence of copied files.

## Update, remove and recover

Treat installed copies as donor-managed. Ordinary `1.15` synchronization replaces
same-path local edits but retains additional local files and files removed
upstream. Removing the donor or plugin leaves existing copies behind. Record
source/installer versions and review target diffs on updates. Change reusable
instructions in their donor, or use a separately named consumer-owned package.
A missing/moved donor source does not prove an old installed copy was removed.

Preview cleanup before any authorized deletion:

```sh
composer skills:update --clean --dry-run
```

Unscoped clean can remove every installed target skill, including handwritten
ones. Do not assume a noninteractive run will ask for confirmation or add a
blanket clean hook. Scoped clean only reinstalls currently selected skills; it
cannot identify a donor already removed from dependencies. Inventory and archive
only the exact stale package outside discovery before cleanup. The
[manual copy guide](skills.md#guarded-manual-copy) supplies guarded backup/update/
restore steps. Adjust donor/trust configuration before removing an installer
copy so the next sync does not recreate it.

Global Composer plugin installation still operates on a selected project/target;
it is not installation into a host's global discovery directory. Explicit global
manual/CLI copies have their own lifecycle. Native discovery and activation
require a refreshed host session and a bounded task after inspecting the copy.

The [llm/skills 1.15.0 source](https://github.com/roxblnfk/skills/tree/1.15.0),
[Composer suggestions](https://getcomposer.org/doc/04-schema.md#suggest) and
[plugin approval](https://getcomposer.org/doc/06-config.md#allow-plugins) provide
first-party confirmation. The Fast Forward Composer installer handles separate
resource-bundle packages; changing this CLI library's type to use it would alter
ordinary runtime installation and is not required for skill distribution.
