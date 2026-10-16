# Published consumer skill

The canonical package is `resources/skills/fast-forward-changelog`, with
`name: fast-forward-changelog` and its own MIT license. The explicit name
avoids collisions with unrelated skills named `changelog`.

`.agents/skills` is reserved for skills consumed by a project. This repository
does not generate host links or maintain Copilot/Claude copies of its published
skill. Consumer installation is an explicit choice; copying a package does not
establish native host activation.

## Discover or install an approved copy

Discover a reviewed local source without writing a consumer skill:

```sh
npx --yes skills@1.7.2 add /path/to/changelog/resources/skills/fast-forward-changelog --list
```

For an authorized project copy, run the installer from the consumer project and
select only the agent actually used. The Composer route in
[composer-skills.md](composer-skills.md) uses `.agents/skills` and keeps aliases
disabled. No user-wide installation or host configuration is performed by this
library.

## Keep the installed name portable

Use `.agents/skills/fast-forward-changelog/SKILL.md` as the default consumer
layout. A category directory such as `fast-forward/changelog` is not a portable
namespace. The [Agent Skills specification](https://agentskills.io/specification)
requires the skill name to match its immediate parent directory; `/` is not
part of the portable name syntax.

The [Codex host loader](https://github.com/openai/codex/blob/859bbc87fc80c18a72e08b181e301ddb4f8dabea/codex-rs/ext/skills/src/loader/host.rs)
uses recursive discovery for ordinary skill roots. In contrast, the
[Gemini CLI loader](https://github.com/google-gemini/gemini-cli/blob/ed96faedfd5571f055c44f2c9509d62321d1138d/packages/core/src/skills/skillLoader.ts)
uses `SKILL.md` and `*/SKILL.md`, and
[OpenCode documents immediate skill directories](https://opencode.ai/docs/skills/).
These checks do not establish broad native compatibility for category folders.

Disposable installation tests with `skills` 1.7.2 found a nested source but
installed it as `.agents/skills/changelog`, without its category. `llm/skills`
1.14.0 installed that same leaf directly under its configured target; explicitly
overriding the target to `.agents/skills/fast-forward` produced the grouped
destination. The Fast Forward installer 0.3.0 also materialized a synthetic
resource bundle into an explicitly mapped nested target. Neither installer
result establishes agent discovery. Prefer the flat, prefixed name for normal
distribution; grouped destinations require consumer-specific validation.

## Package and development boundaries

Composer archives include the canonical `SKILL.md` and `LICENSE` under
`resources/skills`. Repository `.github`, agent configuration, development
scripts, tests and docs are excluded by `.gitattributes`. These archive rules do
not remove files from an explicit Composer source clone.

The packaging fixture verifies the donor path, public identifier and installed
resources. Resource/archive changes require `composer test:packaging` and review
at the final SHA. Native discovery and activation must be checked separately in
the consumer host.

The former Node synchronizer maintained only the retired host links. Removing
that responsibility removes the helper and its tests, without replacing them
with another generator. The four actual PHP development gates are documented in
[scripts/README.md](../scripts/README.md).
