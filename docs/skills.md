# Install the Fast Forward Changelog skill

The `fast-forward-changelog` skill teaches contribution and release review while
consulting the selected runtime's help and documentation. Instructions are text:
installing them does not install the Changelog CLI or activate an agent session.
Use [CPX for the CLI](adoption.md) and Composer only when you need the
[PHP services](adoption.md) in the application.

## Manage project instructions with Composer

Use [llm/skills](https://github.com/roxblnfk/skills) to obtain the instructions
directly from GitHub. From the consumer project:

```sh
composer require --dev llm/skills
composer skills:init
composer skills:add php-fast-forward/changelog
```

Approve only the intended `llm/skills` Composer plugin when prompted. `skills:init`
chooses the discovery target, aliases, trust and automatic synchronization;
select `.agents/skills` and retain auto-sync for the normal project route.
Composer may offer initialization during the first installation; inspect any
existing `skills.json` instead of replacing its settings. Merge with the
project's existing skills and plugin approvals.

`skills:add` records a GitHub source in `skills.json` and fetches the complete
`resources/skills/fast-forward-changelog` package into
`.agents/skills/fast-forward-changelog`. It does not require or install
`fast-forward/changelog` as an application dependency. Add
`--skill=fast-forward-changelog` when selecting only that skill from a donor.
The manager needs its own supported PHP/Composer environment; `llm/skills 1.15`
requires PHP `8.2+`, independently of the Changelog CLI's PHP requirements.

Inspect the generated source/ref and copied license, references and example.
`skills.json` owns the source selection; its GitHub ref can be a version range
and can resolve a newer compatible release on synchronization. Select a reviewed
exact tag or commit when fixed content is required. See
[source policy and updates](composer-skills.md).

### Keep configuration, generate copies

Version `skills.json` and the intended `composer.json` changes. Retain
`composer.lock` according to the project's existing lockfile policy. Do not add
the generated skill copy to Git. Merge these rules into the existing files:

`.gitignore`:

```gitignore
/.agents/
```

`.gitattributes`:

```gitattributes
/.agents/** export-ignore
```

These rules do not untrack existing files. Inspect already tracked content
before any deliberate migration. They apply to the generated consumer directory,
not the library's canonical `resources/skills` payload.

On another clone, normal `composer install` with development dependencies and
the approved plugin reconstructs the instructions from `skills.json` when
auto-sync is enabled. No custom post-install hook is needed. `--no-plugins`,
disabled auto-sync or an installation without the development tool will not
perform that reconstruction.

Inspect the result with `composer skills:show`. Refresh the agent session,
verify that it discovers `fast-forward-changelog`, then perform a bounded
contribution task. A successful file copy is not proof of native activation or
task quality.

The [guided project installer](project-installation.md) offers this same route
alongside the GitHub caller. Its preview must explain the Composer/configuration
changes before application. Existing integrations and skill owners are preserved.

## Other installers

Choose one manager for each copy. `skills.json`, NPX's `skills-lock.json` and a
native `.fast-forward-install.json` receipt are independent formats. Do not
rewrite one to resemble another or sync two managers into the same directory.
Before an authorized migration, preserve the selected copy and local changes
outside discovery, adjust the old source configuration, then verify the new
owner's complete payload. Leave unrelated skills intact.

### NPX alternative

For a project that chooses Node-based skill management instead of Composer:

```sh
npx skills add php-fast-forward/changelog --skill fast-forward-changelog
```

The [Skills CLI](https://github.com/vercel-labs/skills) owns its host/scope choices,
lockfile and update/removal procedure. This route needs no PHP or Changelog
installation. Keep it separate from llm/skills synchronization; a Composer install
does not reconstruct an NPX-owned copy. Inspect the chosen tool's requirements
and help, and select global scope only when explicitly intended.

### Existing native copies

`skill:install` remains a compatibility route for copies already managed by the
Changelog CLI's native receipt. Use the unversioned CPX invocation and inspect
the resolved runtime's help:

```sh
cpx --skip-local fast-forward/changelog help skill:install
cpx --skip-local fast-forward/changelog skill:install --check --json
```

The native check compares local bytes and ownership with that CLI's bundled
payload; it is not a latest-release lookup. Its `installed_source` identifies a
verified intact native copy, while `source` identifies the executing CLI's
payload. Identical bytes can remain current across different package versions.
Use its explicit `--update` only for an intact native copy after reviewing the
change. Local edits, extra files, foreign ownership and known downgrades are
refused. `--dry-run` previews; `--check` and `--update` are exclusive.

Project scope is the native default. Keep the original `--cwd`, relative
`--target` or explicit `--global` options when checking another target. Retained
`.fast-forward-changelog-*.stage` or `.backup` directories are recovery evidence;
preserve them and follow diagnostics before another replacement. A human-terminal
warning is a local comparison and never updates files or queries a registry.
Native management does not adopt llm/skills or NPX copies.

## Distribution verification

`resources/skills/fast-forward-changelog` remains the sole distributed source,
including its license, references and executable example. Run
`composer test:packaging` after payload/archive changes: it compares every skill
file and executes the PHP example and contribution arguments through an isolated
installed CLI. It does not prove CPX, llm/skills synchronization or host activation;
exercise those routes separately in disposable consumers. Validate the final
package with the official [Agent Skills validator](https://agentskills.io/specification#validation).

---

[Composer lifecycle](composer-skills.md) · [Documentation index](index.md)
