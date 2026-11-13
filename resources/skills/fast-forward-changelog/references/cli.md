# CLI maintenance

Load for release previews, history maintenance, recovery or authorized
publication. First select the real release and inspect `list --raw` and
`help <command>` as described in the skill. Read `docs/cli-fragments.md` and the
relevant maintenance/publication guide from that complete release tag. Verify
that its PHP and Git requirements match the environment. Do not infer arguments
or current behavior from an older installed skill.

## Execute the selected CLI

Run from the application's directory. `CHANGELOG_VERSION` is the verified exact
numeric product version, not a range frozen into this skill:

```sh
composer require --dev "fast-forward/changelog:$CHANGELOG_VERSION"
vendor/bin/changelog list --raw
vendor/bin/changelog help status
vendor/bin/changelog help version
```

The Composer proxy executes the ready-built PHAR and its bundled dependencies.
Runtime libraries are embedded and do not constrain the application's Composer
graph. Consumer installations expose the CLI, not PHP service classes. The command
operates on application files and still needs its supported PHP runtime and Git.
Preserve an existing reviewed installation instead of upgrading it incidentally.

Project instructions are managed separately with `llm/skills` and a GitHub donor
recorded in `skills.json`. The CLI's guided project setup can offer that route
when the selected release supports it. Inspect `help install` before use.
Legacy `skill:install` manages only its own native copies; do not use it to update
an llm/skills or NPX target. Runtime updates, instruction synchronization and
host-session activation remain separate results.

## Preview before consolidation

For releases offering these arguments, inspect the read-only plan first:

```sh
vendor/bin/changelog status --json --source=tags --no-interaction
vendor/bin/changelog version --dry-run --source=tags --no-interaction
```

Review the baseline/current/next version, effective impact, accepted fragment
paths, notes and affected files. Choose the relevant maintenance branch and
historical source explicitly. A branch name alone does not establish a release
range. Check command help before treating an exit code as success or failure;
check modes may use a nonzero result for drift as well as errors.

These offline previews omit automatic PR-origin lookup. To reproduce an
integration using `resolve-pull-requests: true`, inspect the selected CLI's help
and add `--resolve-pull-requests=true --repository=owner/repository` to both
commands, with the same GitHub credentials and history options as automation.
The historical source and PR lookup are independent; `--source=tags` alone does
not enable PR lookup.

The normal GitHub lifecycle prepares the version PR. Applying a local version
transaction requires existing consolidation authority and the same reviewed
options as the preview. Preserve exact committed input bytes, custom-template
trust requirements and any PR-resolution setting required by that release.
Verify only central history and consumed-fragment deletions are changed; no
technical plan file or new `Unreleased` section belongs in the version PR.

If interrupted, preserve the recovery journal and remaining files. Follow that
release's recovery procedure with the same inputs. Do not edit a journal, delete
newer notes or replace history merely to force a retry.

## Additional maintenance and publication

Backfill, format, notes extraction and custom templates are optional operations.
Discover their actual commands and read that release's guide before using them.
Keep published descriptions, unknown dates, existing references and pending
fragments intact as required by the operation. Executable PHP templates require
explicit trust. Preview mutations and review the resulting diff.

Publication requires separate authority for an exact approved full commit SHA.
Read that release's publication guide and command help, preview when supported,
and supply the verified target explicitly. Use the application's approved
credential mechanism. Verify the approved version, notes, consumed fragments,
tag and source commit; PR text or a moving branch is not transaction evidence.
Preparation and publication must use the same policies that affect the output.

Do not move conflicting concrete tags or overwrite divergent published notes.
After an uncertain response, inspect remote evidence and follow the selected
release's idempotent recovery route under the existing authority. Report what
was actually published or recovered separately from successful validation.
