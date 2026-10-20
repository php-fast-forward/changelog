# Instruction scope map

## Scope and reading route

[AGENTS.md](https://github.com/php-fast-forward/changelog/blob/main/AGENTS.md) is the sole development contract for source, tests,
docs, resources and internal quality tools. Ordinary directories do not have
different authority and need no child AGENTS.md. The host bootstrap supplements
routing without replacing this contract.

For changelog lifecycle work, read the root and then the published package's
[SKILL.md](https://github.com/php-fast-forward/changelog/blob/main/resources/skills/fast-forward-changelog/SKILL.md). That package
owns its portable consumer procedure, never permission to install or publish.
The root explicitly applies that same procedure to this repository, substituting
`php bin/changelog` for a consumer installation. Resource location does not claim
automatic native-agent discovery; agents follow the explicit root link.

## Current and target hierarchy

| Decision | Path | Responsibility and boundary |
| --- | --- | --- |
| Retain/update | `AGENTS.md` | Development, quality, isolation, internal use of the canonical resource skill, publication authority and Child DOX Index. |
| Retain/update | `resources/skills/fast-forward-changelog/` | Sole distributed skill, license, references and example, with an unambiguous public identifier. |
| Reserve | `.agents/skills` | Skills consumed by the project; no generated copies or aliases of the published resource. |
| Retire | `CLAUDE.md`, `.github/copilot-instructions.md`, old Codex/Claude skill links | Remove unused host-specific distribution adapters; no automatic native-host support claim. |
| Retire | `scripts/skills-sync.mjs`, its test and guide | Link synchronization is no longer a repository responsibility; no replacement generator is needed. |
| Retain | `scripts/{lint,php-files,phpdoc,verify-quality}.php` | Actual development gates; excluded from consumer archives and runtime images. |
| Retain/update | `.github/actions/{check,pr-status,dependabot,version,publish,history}` | Stable public Docker Action paths; metadata and root Docker context remain in shared source archives. |
| Retain | `.github/actions/{github-auth,setup-docker}` | Trusted internal helpers; excluded from consumer archives and not public integration contracts. |
| Add | `.github/workflows/repository-impact.yml`, `.github/scripts/ci-impact.{mjs,md}` | Read-only internal validation classifier and its operational guide; preserves required check identities and contribution validation. |
| Retain/update | `.github/workflows/changelog*.yml` | Seven stable public reusable contracts, kept directly in GitHub's required directory; excluded from Composer archives. |
| Consolidate/rename | `.github/workflows/repository-{ci,changelog,documentation,image}.yml` | Internal quality, one lifecycle caller, read-only documentation builds and product image publication; excluded from consumer archives. |
| Add | `resources/workflows/{changelog.yml,README.md}` | Copyable consumer caller and instructions; not executable here and not a second reusable implementation. |
| Retain/update | `docs/automation-map.md` | Navigation separating stable public interfaces, repository-prefixed workflows, internal helpers and copyable resources. |
| Retain/update | `docs/workflow-reference.md` | Complete public parameters, defaults, secrets, outputs and permissions under the same root authority; no new instruction scope. |
| Retain | `Dockerfile`, `tests/Container` | Packaged CLI and isolated image proof; no real publication authority. |
| Retain/update | Adoption, skill and Composer guides | Explicit installation and distribution contracts, separate from activation and release authority. |
| Retain/update | `src/Installation/`, `docs/project-installation.md` | Explicit project inspection, verified workflow rendering and llm/skills project setup under the same root; existing automation, Composer policy and foreign ownership are preserved. |

The root index points to these durable guides and the canonical package.
No extra child contract is created for resources, scripts or the skill itself.
Guided setup adds no child contract. Read root → project installation guide →
the executing command's help. Its preview does not grant workflow execution,
merge or release authority. Restore source, tests, provider, guide and root index
together on rollback; never remove consumer-owned files as a rollback shortcut.
Default project skill setup delegates to llm/skills with a declared GitHub source.
Read root → skill guide → Composer lifecycle and selected command help. Consumer
`skills.json` and manifest/lock policy preserve the configuration; generated
`.agents/` copies stay ignored and excluded from archives. Normal approved
Composer installation reconstructs them with auto-sync enabled. No new child
scope or in-project host adapter is introduced.
Legacy native skill installation remains source behavior under the same root. Read
root → skill distribution guide → the selected runtime's actual help. The
canonical resource procedure discovers release-specific contracts instead of
freezing an API inventory; it does not authorize installation or incidental
upgrades. Native receipts belong to legacy installed copies, never the donor source
or another installer's lockfile. Copy, ownership and integrity checks do not
establish native host activation.
The [source map](source-layout.md) and
[integration assessment](integration-dependencies.md) retain responsibility for
namespaces and transport boundaries.

The factory simplification preserves that same instruction hierarchy. Ordinary
service construction belongs to the existing root provider through aliases and
autowiring. HTTP client and secure-randomness construction move from standalone
wrapper factory namespaces into that composition root; process, Finder and PHP
configuration loading keep replaceable operational boundaries. Pure value and
standard-exception creation belong to their callers. Domain factories retain
their validation and transaction rules. These are source responsibilities under
the root contract, not new child instruction scopes.

The source map records public FQCN and constructor cleanup before the now-published first
release, `0.1.0`, with migration tables for applications using earlier
development commit pins. Root → source map → affected API remains the
reading route. No host entrypoint or installed consumer skill is rewritten.

The current hierarchy and target hierarchy both have one development root.
Repository CI scoping retains that root: read root →
[classifier contract](https://github.com/php-fast-forward/changelog/blob/main/.github/scripts/ci-impact.md)
→ actual workflow and fixtures. The root index links the helper's same-basename
guide. No child instruction file or public consumer input is added. Validate
with workflow fixtures and actionlint; rollback restores the helper, job gates,
tests and these navigation entries together without changing branch protection.

Workflow organization changes folder responsibilities and navigation, not
authority. Read root → automation map → lifecycle or individual operation guide;
for changelog authoring, read root → canonical resource skill. Root index
summaries identify the automation map as the public/internal contract directory
and the resources guide as the copy/configuration procedure.

Parallel maintenance does not add instruction scopes. The root owns the
branch-selection, backport, review and publication boundaries; the automation
map explains `base-branch`, unique `managed-branch`, committed stable history,
ancestral concrete tags and
separate optional `v1`/`v2` publication channels. Read root → automation map →
reusable lifecycle/operation contract. The package's own `main` caller does not
limit the public reusable's configurable maintenance lines. The repository's
internal channel operation has a same-basename companion in
`.github/update-release-channels.md`; read root → self-changelog guide →
operation contract for an authorized recovery. This adds an operational
entrypoint, not a child instruction scope or a consumer installation.

The lifecycle defaults to publication after an approved version-PR merge; explicit
`publish: false` consolidates without creating a tag or GitHub Release. Future
preparation uses the greatest stable source-history or ancestral-tag version,
independently of earlier publication. Read root → lifecycle guide → workflow
reference for the complete defaults. Keep these instructions, the canonical
skill and the copyable caller consistent; this adds no child contract. Rollback
restores these texts together with the corresponding runtime and workflow rule,
without modifying published history, tags or consumer installations.

## Migration, validation and rollback

The previous source was `.github/skills/changelog`, with Codex/Claude links and
a Node synchronizer. The approved layout places distributable assets under
`resources` and leaves agent directories for consumption. Move source, license,
donor metadata, archive rules, instructions and tests together.

Validate actual installed resources, the archive contents and CLI behavior with
`composer test:packaging`; run package gates and review the final SHA.
`export-ignore` applies to archives, not a full source clone. Copy/discovery
checks do not establish native host activation.

For the automation reorganization, replace the four internal lifecycle callers
together and preserve the seven public addresses. Run `composer test:workflows`,
actionlint, packaging and native checks; verify the remote Docker Action as well
as the local checkout route. If nesting changes a required check context,
migrate only that protection entry after the new context passes. Restore its
previous mapping when rolling back. Public Action archive metadata remains a
deliberate distribution contract rather than an accidental maintenance export.

Rollback restores the resource path, metadata, instructions, adapters and tests
as one reviewed Git change. It never rewrites consumer-managed files, installs
global skills or changes host configuration.

For the factory simplification, restore the source APIs, constructor wiring,
behavioral tests, source map and root responsibility/index entries together.
Validate the root links, package gates, strict coverage and disposable installed
consumers. A change in the number of wrappers is not itself behavioral proof.

For maintenance-line guidance, validate the disposable real-Git rehearsal before
promoting it into the integration suite. Review baseline, exact history, consumed
fragment scope and contextual publication for both lines, plus wrong-line and
managed-branch collision refusals. Inspect reusable lock/routing contracts
separately; an offline fixture does not prove GitHub's live scheduler or signing.
Rollback restores these root/map instructions together, without moving release
or channel refs or changing consumer workflows.

---

[Documentation index](index.md)
