# Portable changelog skill

The original MIT package lives in the real `.github/skills/changelog` directory,
with regular `SKILL.md` and `LICENSE` files. Its procedure is English and agent
agnostic, covering fragment authoring, impact choices, scoped commits,
contribution checks, recovery, historical maintenance and approved publication.
It contains no setup hook or executable helper. Skill installation copies
instructions only; the Composer runtime remains separate.

Composer archives include these canonical resources and declare their donor path.
Consumers can opt into the verified [Composer skill installer route](composer-skills.md),
which keeps Copilot's target real and leaves plugin activation and copying explicit.
The same guide records the Fast Forward resource-bundle alternative.

## Canonical source and repository host paths

Edit `.github/skills/changelog` only. Copilot's conventional package path contains
real resources. Repository `.agents/skills/changelog` and
`.claude/skills/changelog` are directory symlinks to that same package, with the
exact relative target `../../.github/skills/changelog`. Edits become visible
through both links without regenerating copies.

The [link verifier](../scripts/skills-sync.md) checks the canonical package and
both adapters without writing by default. It creates missing links or migrates
byte-identical legacy directories only with `--write`; manual, divergent and
unknown resources are preserved. `CLAUDE.md` and `.github/copilot-instructions.md`
route to the root `AGENTS.md` and canonical procedure. The skill's `SKILL.md`
remains its package entrypoint; no duplicate child instruction file is required.

On Windows, a checkout with `core.symlinks=false` may expose a link as a text file.
The verifier accepts only exact bytes corroborated by Git index mode `120000`
and reports `materialized=false`. Copilot's real package remains accessible;
that fallback does not establish native Codex/Claude link discovery. Separately
authorized copy installation avoids depending on symlink support in consumers.

## Discover and install deliberately

Discover a reviewed local source without installing into a host environment:

```sh
npx --yes skills@1.7.0 add /path/to/changelog/.github/skills/changelog --list
```

When separately authorized, run project copy installation from the intended
consumer repository:

```sh
npx --yes skills@1.7.0 add /path/to/changelog/.github/skills/changelog --skill changelog --agent codex claude-code github-copilot --copy --yes
npx --yes skills@1.7.0 list --agent codex claude-code github-copilot
```

Project scope is the default. Review an existing same-name skill before
replacement. For a published reviewed source, use an immutable tree URL such as
`https://github.com/php-fast-forward/changelog/tree/<reviewed-sha>/.github/skills/changelog`.
Verify that revision after it exists remotely; local discovery does not prove
published availability. Installing instructions does not install PHP or Composer,
create runtime configuration, authorize commits or authorize releases.

The examples pin the previously inspected official
[Skills CLI](https://github.com/vercel-labs/skills) distribution 1.7.0
(Node >=22.20.0). Its recorded registry integrity is
`sha512-OfePnDft+Xt9/tCoHdCUe5fkM8i+Q3QOSQO53hm7mKtsXyvc+CKOAAliVWZ484HS3cWx+6r+ob0AArixs3jYXw==`.
That CLI version's earlier disposable copy fixture installed Codex/Claude regular
files and used a shared `.agents` location for the Copilot identifier. Inspect
the actual consumer output instead of assuming that its agent label establishes
native Copilot activation.

These project entrypoints are described in first-party
[Codex](https://learn.chatgpt.com/docs/build-skills),
[Claude Code](https://code.claude.com/docs/en/skills) and
[Copilot](https://docs.github.com/en/copilot/concepts/agents/about-agent-skills)
documentation. This repository ships real Copilot resources and linked
Codex/Claude paths. Native activation inside any application remains untested.

## Validation evidence and limits

The earlier 2026-10-03 format/distribution assessment validated the original MIT
procedure and its then-generated regular copies using official `skills-ref`
0.1.0 at reviewed revision
[`69ef37e9424c0a7ea9dd2293b559e43ec8176379`](https://github.com/agentskills/agentskills/tree/69ef37e9424c0a7ea9dd2293b559e43ec8176379/skills-ref).
Its downloaded source archive SHA-256 was
`0c9eabbe602095c4f4d771ee55bf74f6bc7e1c770f25d4fe29ce9802981daa20`.
The license is retained, and the procedure now documents order-independent
stable notes selection and publication from committed historical sections. The
[Agent Skills specification](https://agentskills.io/specification#validation)
format check does not certify instruction quality or native compatibility.

The current link verifier passes 17 disposable Node fixtures covering no-write
checks, exact in-repository links, idempotence, legacy migration, preservation
of manual/unknown resources, limits, CLI arguments and corroborated Windows
link-text fallback. The fallback fixture proves representation verification,
not a working native Windows directory symlink. Native link fixtures explicitly
skip when Windows lacks creation privileges.

The canonical real `.github` package is separately checked with pinned
`skills@1.7.0 --list` in isolated HOME, XDG and npm cache directories, with
telemetry disabled and no host installation. Installer discovery, format
validation and native host activation are distinct claims. No user config,
Composer runtime, real credential, Git tag or GitHub release is installed or
published by these fixtures.
