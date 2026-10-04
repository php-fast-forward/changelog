# Portable changelog skill

The original MIT package is `.agents/skills/changelog`, with `SKILL.md` and
`LICENSE`. Its procedure is English and agent agnostic, with complete ordinary
CLI examples, metadata/impact choices, scoped commits, contribution checks,
transaction recovery, historical maintenance and approved-SHA publication.
It contains no setup hook, executable helper or host-specific tools permission.
Installing it copies instructions only; the Composer runtime is separate.

## Discover and install deliberately

Use an explicitly reviewed local repository checkout when developing or testing:

```sh
npx --yes skills@1.7.0 add /path/to/changelog/.agents/skills/changelog --list
npx --yes skills@1.7.0 add /path/to/changelog/.agents/skills/changelog --skill changelog --agent codex claude-code github-copilot --copy --yes
npx --yes skills@1.7.0 list --agent codex claude-code github-copilot
```

Run the install command from the intended consumer repository. Project scope is
the default; no `--global` is needed. Review an existing skill of the same name
before overwriting it. Copy mode avoids requiring symlink behavior across hosts.
For a published reviewed source, replace the local path with its immutable tree
URL, for example `https://github.com/php-fast-forward/changelog/tree/<reviewed-sha>/.agents/skills/changelog`.
Public discovery of that revision must be verified after it actually exists;
a local overlay test does not establish remote availability.

The CLI version is pinned to the tested official
[Skills CLI](https://github.com/vercel-labs/skills) npm distribution 1.7.0
(Node >=22.20.0). Its registry integrity is
`sha512-OfePnDft+Xt9/tCoHdCUe5fkM8i+Q3QOSQO53hm7mKtsXyvc+CKOAAliVWZ484HS3cWx+6r+ob0AArixs3jYXw==`.
Installation and activation do not create runtime configuration, install Composer
dependencies or authorize commits/publication. No live user installation was
performed while preparing this package.

## Canonical source and host adapters

Edit `.agents/skills/changelog` only. Repository `.claude/skills/changelog` and
`.github/skills/changelog` are generated real copies, including LICENSE; drift
is checked by the [copy verifier](../scripts/skills-sync.md). `CLAUDE.md` and
`.github/copilot-instructions.md` point to the root `AGENTS.md` authority and
canonical procedure without duplicating it. No per-folder `AGENTS.md` is required.

| Consumer | Repository package shipped | Skills CLI 1.7.0 fixture result | Native activation |
| --- | --- | --- | --- |
| Codex | `.agents/skills/changelog` | Copied as regular files into `.agents/skills/changelog` | Not exercised |
| Claude Code | `.claude/skills/changelog` | Copied as regular files into `.claude/skills/changelog` | Not exercised |
| GitHub Copilot | `.github/skills/changelog`, with canonical `.agents` package also present | Uses shared `.agents/skills/changelog` in this CLI version | Not exercised |

These project locations follow current first-party
[Codex](https://learn.chatgpt.com/docs/build-skills),
[Claude Code](https://code.claude.com/docs/en/skills) and
[Copilot](https://docs.github.com/en/copilot/concepts/agents/about-agent-skills)
documentation. The shipped copies and local installer outputs were inspected.
That structural/discovery evidence does not prove model activation or behavior
inside the three live applications, and no universal symlink claim is made.

## Recorded validation

The 2026-10-03 local run performed task-specific skills.sh/primary-repository
discovery, current primary-source research and process-owner reconciliation.
No external candidate text/code was copied: the inspected candidate's central
editing procedure did not match this fragment workflow, and scoped licensing
was unavailable. This package uses the project's explicit MIT decision.

All three packages passed official `skills-ref validate`, version 0.1.0, from
reviewed source revision `69ef37e9424c0a7ea9dd2293b559e43ec8176379` of
[agentskills/agentskills](https://github.com/agentskills/agentskills/tree/69ef37e9424c0a7ea9dd2293b559e43ec8176379/skills-ref).
The downloaded source archive SHA-256 was
`0c9eabbe602095c4f4d771ee55bf74f6bc7e1c770f25d4fe29ce9802981daa20`.
Its frozen dependency lock was installed only in a disposable Python environment.
The [Agent Skills specification](https://agentskills.io/specification#validation)
defines that format check; it establishes frontmatter/naming conformance and
does not certify instruction quality or native host compatibility.

The copy helper passed 16 disposable integration fixtures: no-write drift,
copy equality/idempotence, nested resources, preservation of unknown files,
missing prerequisites, unsafe symlink ancestors, file/depth bounds and CLI errors.
Pinned `npx skills@1.7.0 add ... --list` found the local package; project `--copy`
installed it for the three requested agent identifiers in a disposable consumer.
Synthetic installer state, npm cache and configuration were isolated; telemetry
was disabled. No PHP runtime, user config, real credentials, Git tag or release
was installed or published by those tests.

A manual product fixture also exercised the ordinary add/check route with actual
parser, renderer and isolated file/lock adapters. It preserved meaningful
Markdown spaces, accepted an explicit lower impact, rejected inherited-only PR
evidence and filename overwrite, and left central/unrelated files untouched.
Git was injected; no real commit ran. Manual procedure review covered recovery,
maintenance and publication decisions; it is not a native model benchmark.

Native host exercises and comparative prompt-quality benchmarks remain separate
from these checks. Review this evidence and the exact candidate diff before
adopting it in another repository; do not interpret a file copy as activation.
This evidence supports bounded pilot adoption. Repeated useful native use and
an explicit review decision are still needed before claiming stable maturity.
