# Composer skill sources and lifecycle

Follow [first installation](skills.md) for the minimal project route. The
development dependency is `llm/skills`; the instructions come from the
`php-fast-forward/changelog` GitHub source. The application does not need the
Changelog runtime dependency. The manager's plugin approval and Composer
requirements are separate from running Changelog through CPX.

## Review the source policy

`skills.json` owns target, sources and synchronization. The guided Changelog
installer uses a narrow GitHub-only configuration for a new project:

```json
{
  "target": ".agents/skills",
  "aliases": [],
  "discovery": false,
  "auto-sync": true,
  "vendor-sources": false,
  "dependencies": {"composer": false},
  "sources": [
    {
      "from": "github",
      "package": "php-fast-forward/changelog",
      "skills": ["fast-forward-changelog"]
    }
  ]
}
```

This example shows the source policy; inspect and retain the actual `ref` written
by `skills:add` for the selected release. Do not replace an existing manifest
wholesale. `dependencies.composer:false` disables installed-package donor
discovery; `vendor-sources:false` ignores remote sources advertised by vendor
packages. Explicit project `sources` are trusted by declaration. `aliases:[]`
creates no additional host-directory links.

An interactive `skills:init` can choose different project settings. Preserve
deliberate sources, trust and aliases. The GitHub route is independent of the
library's optional Composer donor metadata (`extra.skills.source` and the
`llm/skills` suggestion); a suggestion neither installs nor approves a plugin.

For a selected source, inspect the manager's current help for `--ref` and
`--skill` before changing policy. A compatible version range may advance when
synced; an exact reviewed tag or commit fixes the requested source more narrowly.
`composer.lock` fixes the manager/dependency graph, not the content behind a
moving GitHub ref.

Guided Changelog setup supports stable llm/skills `1.x` from `1.15.0`, adding
`^1.15` only for a new dependency. It preserves existing requirements and checks
lock/installed metadata. Without that evidence, unsupported or ambiguous
constraints require owner review before setup continues. Its source must use
the canonical GitHub host; an explicit `github.com` host is preserved when
delegating commands. Other source hosts remain for manual manager review.

## Inspect, synchronize and restore

```sh
composer skills:show
composer skills:update fast-forward/changelog --dry-run
composer skills:update fast-forward/changelog
```

The GitHub repository selector is `php-fast-forward/changelog`; the resolved
donor is named `fast-forward/changelog` by its Composer manifest. Use the donor
name shown by `skills:show` when filtering updates.

Inspect the report and complete copied payload. Normal approved Composer
install/update invokes synchronization when auto-sync is enabled. With
`llm/skills 1.15`, `--no-scripts` alone does not disable the plugin's auto-sync;
use `auto-sync:false` for deliberate manual synchronization or `--no-plugins` to
prevent plugin loading. Recheck behavior when changing manager versions.

Keep the generated `.agents/` directory ignored and excluded from archives as
described in [installation](skills.md). A new development clone's
`composer install` restores it according to the configured sources and refs.
Network/source availability still matters: a green Composer exit alone does not
prove every remote donor resolved or every copy is current. Read its report.

## Updates, removal and ownership

Ordinary synchronization replaces files shipped by the donor, including local
edits at those paths, while retaining additional files and files removed upstream.
Change reusable instructions in their donor or a separately named local skill.
Before updating an edited copy, preserve the selected directory outside discovery.

Removing a source or the plugin does not remove existing copies. Change
`skills.json` first so the next sync does not recreate the removed donor, then
review and archive only that copy before deletion. Cleanup has broader effects:

```sh
composer skills:update fast-forward/changelog --clean --dry-run
```

Scoped clean reinstalls selected current skills; it cannot identify an already
removed donor. Unscoped `--clean` can delete handwritten skills too, and an
unattended run may not ask first. Do not add a blanket clean hook.

NPX locks and Changelog's native receipt retain their own owners. Global scope
is an explicit separate choice, not a consequence of installing a Composer
plugin. File synchronization does not activate an agent session.

See [llm/skills](https://github.com/roxblnfk/skills),
[Composer plugin approval](https://getcomposer.org/doc/06-config.md#allow-plugins)
and [Composer suggestions](https://getcomposer.org/doc/04-schema.md#suggest).

---

[Skill installation](skills.md) · [Documentation index](index.md)
