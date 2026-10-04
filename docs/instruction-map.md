# Instruction scope map

## Scope and current hierarchy

The repository has one development contract: [AGENTS.md](../AGENTS.md). It
owns source, tests, documentation, quality, isolation and publication authority.
The host bootstrap supplements command routing; it does not replace this
repository contract. Ordinary source and test directories share the root rules
and have no child `AGENTS.md` files.

```text
AGENTS.md                              repository development and authority
CLAUDE.md                              Claude adapter to the root and skill
.github/copilot-instructions.md        Copilot adapter to the root and skill
.github/skills/changelog/SKILL.md       real canonical consumer procedure
.agents/skills/changelog                directory link to the canonical package
.claude/skills/changelog                directory link to the canonical package
scripts/skills-sync.mjs                 bounded package/link verifier
scripts/skills-sync.md                  helper operation and recovery guide
```

The [canonical skill](../.github/skills/changelog/SKILL.md) is a self-contained
package with its own procedure. It describes fragment contributions, checks,
release planning, historical maintenance and approved publication. It does not
grant installation, commit or publication authority. Codex and Claude
directory links resolve to this same package and license; neither is an
independent source of policy. Copilot reads real resources at its own path.

## Target decisions and durable entrypoints

The product retains this minimal hierarchy. New domain, GitHub and automation
directories do not introduce different development authority, so they do not
need additional instruction contracts.

| Decision | Entrypoint | Responsibility and boundary |
| --- | --- | --- |
| Retain and update | [AGENTS.md](../AGENTS.md) | Own repository development and the durable reading index; retain quality, isolation and authority boundaries. |
| Retain | [CLAUDE.md](../CLAUDE.md), [Copilot instructions](../.github/copilot-instructions.md) | Route hosts to the root and canonical package without duplicating procedures. |
| Retain | [Changelog SKILL.md](../.github/skills/changelog/SKILL.md) | Own the portable consumer procedure; runtime installation and publication require existing authorization. |
| Migrate | Codex and Claude skill directory links | Point to the real `.github/skills/changelog` canonical package; preserve manual/unknown resources during migration. |
| Retain | [Adoption guide](adoption.md) | Explain consumer runtime and workflow adoption, with explicit installation and release boundaries. |
| Retain | [Skill distribution guide](skills.md) | Record layout, discovery and validation evidence without claiming native host activation. |
| Retain | [skills-sync.md](../scripts/skills-sync.md) | Explain the helper's inputs, bounded links, drift checks and recovery. |
| Do not create | Procedural `AGENTS.md` files in ordinary folders or the skill package | Keep common development rules at the root and package procedures in `SKILL.md`. |

The root Child DOX Index links the canonical skill and the three durable guides
above. Each entry states its responsibility, output and material limit. The
guides are operational navigation, not additional scoped instruction contracts;
host links do not receive separate child-index entries.

## Reading and maintenance route

Read the root contract before changing source, tests, configuration or docs.
Claude and Copilot adapters direct the same reading route. For changelog
lifecycle work, load the canonical skill after the root. To maintain its
distribution, read the [link helper guide](../scripts/skills-sync.md), edit the
real canonical package, then check the two adapters. Create/migrate links only
when needed:

```sh
node scripts/skills-sync.mjs --write
node scripts/skills-sync.mjs --check
```

Repository-local command routing may wrap these invocations with RTK. The helper
does not install user skills or modify host links. Its script location determines
the repository root, independent of the caller's working directory.

## Migration, validation and rollback

The foundation map deferred a distributable skill and host adapters. The product
now ships those entrypoints and replaces that deferred map with the hierarchy
above. The earlier layout used a canonical `.agents` package with generated regular
copies for Claude/Copilot. The target makes `.github` the real canonical package
and replaces only matching Codex/Claude copies with relative directory links.
Keep the root index, host adapters, canonical package, links and operator guide
together when updating their paths or responsibilities.

Validate relative links, the absence of duplicate child contracts, a real
canonical package and `skills-sync.mjs --check`. Directory links resolve within
the repository to `../../.github/skills/changelog`. Windows tracked link-text
fallbacks are reported as unmaterialized. Format validation and disposable
fixtures establish package structure and link/discovery behavior; native host
activation remains separate evidence recorded in [skills.md](skills.md).

Rollback restores the affected instruction files, index, package resources and
host links as one reviewed Git diff. No external installation or host
configuration forms part of this migration.
