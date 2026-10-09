# Packaged Docker CLI

The root [Dockerfile](../Dockerfile) installs the locked production dependencies
at build time and runs `/opt/changelog/bin/changelog` directly. PHP 8.5 and Git
are included. Composer, development tools and action scripts are absent from the
runtime image. PHP and Composer image sources are pinned by version and digest.
The allowlisted build context excludes host credentials, Git state and vendor
directories. No image registry publication is required to use the Dockerfile.

Build and inspect locally:

```sh
docker build -t fast-forward-changelog:local .
docker run --rm fast-forward-changelog:local list --raw
```

For consumer commands, mount the repository and select its working directory.
The installed package remains outside the mounted data:

```sh
docker run --rm \
  --volume "$PWD:/github/workspace" \
  --workdir /github/workspace \
  fast-forward-changelog:local status --source=tags

docker run --rm \
  --volume "$PWD:/github/workspace" \
  --workdir /github/workspace \
  fast-forward-changelog:local add "Describe the observable change" --category=fixed --type=patch
```

The ordinary `add/check/status/version/notes/publish/backfill/format` commands
retain their existing flags and behavior. Local mutations affect only the
mounted consumer. A dry-run remains read-only. Publication requires its existing
approval and exact committed evidence.

## Direct GitHub operations

The native command `changelog github <operation>` delegates to the same shared
automation services as the workflows. Its operations are `check`, `dependabot`,
`version`, `publish` and `history`:

```sh
php bin/changelog github check --since=origin/main --repository=owner/project
php bin/changelog github version --repository=owner/project --dry-run=true
php bin/changelog github history --operation=format --dry-run=true --source=tags
```

Run `github --help` for explicit metadata and commit inputs. Automation boolean
options accept `true` or `false`; ordinary CLI preview flags remain unchanged.
Unspecified options preserve operation defaults, and options from another
operation are rejected. Success prints one raw JSON result with exit 0. Invalid
input exits 2; failed operations exit 1 with JSON diagnostics on stderr.

Credentials are environment inputs, outside command arguments. The executable
uses `FF_CHANGELOG_TOKEN` when explicitly configured, otherwise `GITHUB_TOKEN`.
An explicitly empty Action token remains empty. `GITHUB_API_URL` selects the
trusted API endpoint. With `GITHUB_OUTPUT` configured, the CLI appends its
complete `result` and declared scalar outputs in one locked write. Invalid
scalars fail before the output file changes. Git processes do not inherit
GitHub tokens.

## Action layout and runner paths

The five metadata files live in [`.github/actions/`](../.github/actions/). Each
uses `../../../Dockerfile`, passes direct CLI args and forwards its token through
the environment. Docker actions run on Linux runners. GitHub builds from the
Dockerfile's parent directory and mounts consumer data at `/github/workspace`;
the CLI stays at `/opt/changelog`. No shell entrypoint or action PHP script is
needed. See the [GitHub runner implementation](https://github.com/actions/runner/blob/main/src/Runner.Worker/Handlers/ContainerActionHandler.cs)
and [Docker action documentation](https://docs.github.com/en/actions/tutorials/use-containerized-services/create-a-docker-container-action).

Reusable workflows check out trusted runtime code and consumer data separately.
They pass container-relative `project/<working-directory>` paths. Direct local
Actions use `.` for the repository root. Monorepo PR operations keep that root
and select nested fragment/history paths through their normal inputs. Token
permissions, event validation, signatures and committed publication proof are
still enforced by the existing services.

Action resources and the root Dockerfile remain available in repository
archives; workflow and skill folders are excluded individually from Composer
archives. Old action paths remain available at older pinned commits. Update
consumers to `.github/actions/<operation>` at a reviewed immutable commit when
adopting this layout.

## Verification and recovery

Run the [actual-image verifier](../tests/Container/verify.md) after building. It
uses disposable Git repositories, synthetic credentials and network-disabled
containers. Native unit/coverage checks still run on Linux and Windows. Linux
CI also invokes a real local Docker action and checks its runner outputs.

Build failures leave consumer data untouched. Operation failures retain the
existing service recovery rules; inspect the exact result and remote state
before repeating a write. Rollback restores the Dockerfile, CLI adapter,
metadata, workflows and documentation together. Pushing an image to a registry
or publishing real tags/releases requires separate authorization.
