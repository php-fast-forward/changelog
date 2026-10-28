# Released major channel updater

This repository-only PHP operation maintains branches `v<major>` for
`php-fast-forward/changelog`. It is separate from the changelog CLI/domain and
uses the existing Symfony HttpClient dependency. Neither this entrypoint nor
`ReleaseChannelUpdater.php` is distributed in Composer archives. No Node runtime,
plugin, dotenv loader or new package dependency belongs to this operation.

## Inputs and authorized use

Use after an already approved stable release was published, or to recover a
failed channel update. It cannot create a release, complete missing publication,
move a full release tag or merge a version PR. Creating or advancing a real
channel still requires the maintainer's authority for that operation.

The workflow `.github/workflows/repository-release-channels.yml` is the normal
entrypoint. The repository lifecycle calls it explicitly from successful
publication outputs; it does not depend on `GITHUB_TOKEN` generating another
release/tag event. Manual recovery runs the workflow from `main` with:

- `tag`: newest existing published stable full tag for the selected major;
- `expected-sha`: that tag's exact forty-character commit SHA, not the current
  default-branch SHA.

The workflow accepts only the canonical repository. Automatic calls require the
closed merged managed PR event and the exact approved merge SHA. Manual dispatch
is restricted to `refs/heads/main`. Code is checked out at the called workflow's
verified repository and exact implementation SHA, never the PR head or release notes.

The channel workflow accepts no caller token and has no `CHANGELOG_TOKEN` or
`github.token` fallback. Its built-in token is read-only. The protected
`release-channels` environment provides `RELEASE_CHANNEL_APP_CLIENT_ID`,
`RELEASE_CHANNEL_APP_SLUG` and the secret `RELEASE_CHANNEL_APP_PRIVATE_KEY`.
Only the dedicated App installation token reaches the updater. It is scoped to
this repository with Contents write, Workflows write (historical workflow refs)
and Pull requests read (managed-merge evidence), and revoked at job completion.
The App has no administration permission and is never used to publish releases.

See [the protection proposal and activation procedure](release-channel-policy/README.md)
for exact offline-rendered payloads, identity boundaries, approval requirements,
current remote findings and residual risks. Local invocation still requires an
explicitly authorized credential; it is no longer a documented human recovery
route. Use the guarded workflow on `main` to recover. Missing App credentials or
unproven evidence fail closed; do not substitute a PAT or unprotect the branch.

## Effects and refusal conditions

The updater reads existing GitHub release/tag/branch metadata and performs at
most one GraphQL `updateRefs` mutation for `refs/heads/v<major>`. The expected
old OID is mandatory, including an all-zero OID for branch creation, and
`force:false` requires a fast-forward. It never writes a tag or release.

It verifies immutable, published, non-draft, non-prerelease state; full stable SemVer;
repository identity; tag-to-commit binding; the exact merged same-repository
`changelog/version` PR into `main`; and a complete release catalog for
the major. Annotated tags are peeled through at most sixteen objects, and the
catalog is bounded to ten pages of one hundred releases. Associated PR evidence
is bounded to fewer than one hundred entries and must contain exactly one matching
managed merge. Mutable releases and arbitrary published commit selectors are refused. Evidence beyond those
bounds, malformed responses and API/permission failures block the update.

An existing branch must be an older published release commit from the same
major. Unrelated commits, divergent history, a homonymous abbreviated tag and
ambiguous highest versions that differ only in build metadata are refused.
SemVer numeric components are compared without integer conversion or overflow.
No ancestry between different major channels is required.

The selected release and catalog are read again before the mutation. A
concurrent branch change fails the exact old-OID condition; no force update or
automatic takeover recovers that failure. Full release tags remain untouched.
Repository rules may reject the write. The dedicated App bypasses only the
separate update restriction, never creation, deletion or non-fast-forward rules.
With the proposed rules active, recreation of a missing `v0` fails even though
the generic updater supports creation for unprotected future channels.

## Output and recovery

Success prints one JSON record containing `state`, `channel`, `tag` and `sha`.
States are `created`, `advanced`, `current` or `superseded`; the latter two do
not mutate refs. An older selector is accepted as `superseded` only when the
channel already points to the newest release. Otherwise select the newest
tag/SHA explicitly for recovery. Exit `0` means confirmed success, `2` means
invalid invocation/missing token and `1` means failed verification or mutation.
Rejected GraphQL writes report only recognized error categories, such as
`FORBIDDEN`; raw server messages and authentication context are never printed.

After an uncertain API response, inspect the actual branch and rerun the same
verified selector under existing authority. If the accepted update already
matches, the rerun returns `current` without another mutation. Never delete a
foreign ref, rewrite full tags or point at `main` to make a retry succeed.

The global channel lock serializes workflow runs but does not replace the
server-side old-OID condition. `@v0` advances across `0.x` minors and may include
breaking development changes; it is not the same range as Composer `^0.1`.
See [this repository's release guide](../docs/self-changelog.md).

## Verification

`composer test:workflows` checks the actual YAML guards and invokes
`tests/Workflow/ReleaseChannelUpdaterTest.php`. Those fixtures use a mock HTTP
transport and disposable real Git histories/ref transactions, with synthetic
identities and isolated configuration. They perform no network request, real
publication or remote ref update. They verify success/idempotence, monotonic
versions, refused evidence, annotated tags, bounded lookup, divergent histories,
changed release evidence, CAS races and uncertain-response recovery. Actionlint
validates the caller and channel workflow. Live GitHub execution remains a
separately authorized deployment observation.
