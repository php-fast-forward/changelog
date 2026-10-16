# Instruction scope map

## Scope and reading route

[AGENTS.md](../AGENTS.md) is the sole development contract for source, tests,
docs, resources and internal quality tools. Ordinary directories do not have
different authority and need no child AGENTS.md. The host bootstrap supplements
routing without replacing this contract.

For changelog lifecycle work, read the root and then the published package's
[SKILL.md](../resources/skills/fast-forward-changelog/SKILL.md). That package
owns its portable consumer procedure, never permission to install or publish.

## Current and target hierarchy

| Decision | Path | Responsibility and boundary |
| --- | --- | --- |
| Retain/update | `AGENTS.md` | Development, quality, isolation, publication authority and Child DOX Index. |
| Relocate/rename | `resources/skills/fast-forward-changelog/{SKILL.md,LICENSE}` | Sole distributed skill, with an unambiguous public identifier. |
| Reserve | `.agents/skills` | Skills consumed by the project; no generated copies or aliases of the published resource. |
| Retire | `CLAUDE.md`, `.github/copilot-instructions.md`, old Codex/Claude skill links | Remove unused host-specific distribution adapters; no automatic native-host support claim. |
| Retire | `scripts/skills-sync.mjs`, its test and guide | Link synchronization is no longer a repository responsibility; no replacement generator is needed. |
| Retain | `scripts/{lint,php-files,phpdoc,verify-quality}.php` | Actual development gates; excluded from consumer archives and runtime images. |
| Retain | `.github/actions`, `.github/workflows` | Thin CLI action metadata and orchestration, consumed through GitHub; excluded from Composer archives. |
| Retain | `Dockerfile`, `tests/Container` | Packaged CLI and isolated image proof; no real publication authority. |
| Retain/update | Adoption, skill and Composer guides | Explicit installation and distribution contracts, separate from activation and release authority. |

The root index points to these durable guides and the canonical package.
No extra child contract is created for resources, scripts or the skill itself.
The [source map](source-layout.md) and
[integration assessment](integration-dependencies.md) retain responsibility for
namespaces and transport boundaries.

## Migration, validation and rollback

The previous source was `.github/skills/changelog`, with Codex/Claude links and
a Node synchronizer. The approved layout places distributable assets under
`resources` and leaves agent directories for consumption. Move source, license,
donor metadata, archive rules, instructions and tests together.

Validate actual installed resources, the archive contents and CLI behavior with
`composer test:packaging`; run package gates and review the final SHA.
`export-ignore` applies to archives, not a full source clone. Copy/discovery
checks do not establish native host activation.

Rollback restores the resource path, metadata, instructions, adapters and tests
as one reviewed Git change. It never rewrites consumer-managed files, installs
global skills or changes host configuration.
