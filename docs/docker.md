# Packaged Docker CLI

The root [Dockerfile](https://github.com/php-fast-forward/changelog/blob/main/Dockerfile) installs the locked production dependencies
at build time and runs `/usr/local/bin/changelog` directly. PHP 8.5 and Git
are included. Composer, development tools and action scripts are absent from the
runtime image. PHP and Composer use explicit full version tags without digests.
The runtime layout is `/usr/local/bin/changelog`, `/usr/local/src` and
`/usr/local/vendor`, so the ordinary CLI relative autoloader remains coherent.
The allowlisted build context excludes host credentials, Git state and vendor
directories. No image registry publication is required to use the Dockerfile.

Build and inspect locally:

```sh
docker build -t fast-forward-changelog:local .
docker run --rm fast-forward-changelog:local list --raw
```

`COMPOSER_ROOT_VERSION` is a build argument with the default `dev-main`.
Override it for a versioned image:

```sh
docker build --build-arg COMPOSER_ROOT_VERSION=0.1.0 -t fast-forward-changelog:0.1.0 .
docker run --rm fast-forward-changelog:0.1.0 --version
```

The final image copies only the package executable, source, installed vendor
and metadata. The build-stage Composer binary stays outside the runtime image.

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
automation services as the workflows. Its operations are `check`, `pr-status`,
`dependabot`, `version`, `publish` and `history`:

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

The six public Docker Actions live in
[`.github/actions/`](https://github.com/php-fast-forward/changelog/tree/main/.github/actions): `check`, `pr-status`, `dependabot`,
`version`, `publish` and `history`. Each uses `../../../Dockerfile`, passes direct
CLI args and forwards its token through the environment. The `github-auth` and
`setup-docker` composites are internal workflow helpers. Docker Actions run on
Linux runners with Docker available, including self-hosted runners. GitHub builds
from the Dockerfile's parent directory and mounts consumer data at `/github/workspace`;
the CLI stays at `/usr/local`. No shell entrypoint or action PHP script is
needed. See the [GitHub runner implementation](https://github.com/actions/runner/blob/main/src/Runner.Worker/Handlers/ContainerActionHandler.cs)
and [Docker action documentation](https://docs.github.com/en/actions/tutorials/use-containerized-services/create-a-docker-container-action).

Reusable workflows check out trusted runtime code and consumer data separately.
They pass container-relative `project/<working-directory>` paths. Direct local
Actions use `.` for the repository root. Monorepo PR operations keep that root
and select nested fragment/history paths through their normal inputs. Token
permissions, event validation, signatures and committed publication proof are
still enforced by the existing services.

The six public Action metadata files, shared Dockerfile, `.dockerignore`,
`composer.json`, `composer.lock`, license, executable and complete `src/` tree
remain in Git archives. The packaged skill and consumer workflow example remain
in `resources/`. Repository workflows, issue/PR templates, funding configuration
and the two internal composites are excluded individually, along with development
files. Keeping the root Docker build context is necessary for remote Action use.

A Composer installation using the GitHub dist archive receives that same small
Action payload. `composer.json`'s `archive.exclude` controls archives created by
Composer; it does not provide a separate filter for a GitHub-hosted dist ZIP.
See [Git archive attributes](https://git-scm.com/docs/gitattributes#_creating_an_archive)
and [Composer archive configuration](https://getcomposer.org/doc/04-schema.md#archive).
Public `.github/actions/<operation>` paths remain stable; consumers select a
reviewed immutable commit during validation.

## Versioned GHCR publication

[Publish Docker CLI](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/repository-image.yml) accepts an existing
semantic release tag. The repository publication workflow calls it explicitly
after approved release publication, supplying its exact approved SHA. Failed
image jobs can be rerun from that publication run. This single launch path
works with GITHUB_TOKEN and App tokens
without also dispatching a duplicate run from a tag-push event.

The validation matrix binds the tag to the checked-out commit and the exact
approved SHA. It builds and executes each platform variant on a native GitHub
runner with the normalized
version, running the actual-image verifier and checking the complete CLI version.
The publication job
checks out that validated commit, prepares the multi-platform build, then
rechecks the remote tag's exact commit immediately before the cached registry
publication. Missing, moved or invalid tags fail before that push. It publishes
linux/amd64 and linux/arm64 images
to `ghcr.io/php-fast-forward/changelog:<version>` with provenance, SBOM and
repository/version/commit labels. Only the publication job receives
`packages: write`. Image tags use the full version, without moving aliases.

Community Actions use explicit full version tags. Consumer references to this
package use reviewed immutable commits. Docker base images also use full version
tags.

The Action metadata builds the shared Dockerfile. Consumers may instead run a
prebuilt CLI after verifying the selected versioned image exists and is accessible
to their runner. Use the following example only after the `0.1.0` image has been
published and made accessible to that runner:

```yaml
- uses: docker://ghcr.io/php-fast-forward/changelog:0.1.0
  env:
    GITHUB_TOKEN: ${{ github.token }}
  with:
    args: github check --since=origin/main --repository=owner/project
```

The published image avoids installing dependencies or rebuilding the application
in each consumer job. The GitHub CLI commands and runner output contract are the
same as the Dockerfile-backed Actions. Select an existing image deliberately;
building the local Dockerfile does not publish it to a registry.

A new GHCR package may initially be private. After its first publication, set its
visibility to public in the package settings for anonymous external pulls; the
source label links it to the repository. Private package consumers need registry
authentication and package access. See [the Container registry guide](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry).

## Verification and recovery

Repository workflows prepare public base images through Google's Docker Hub
cache on disposable GitHub-hosted Linux runners. The internal
[`setup-docker`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/setup-docker/action.yml) action preserves
other daemon settings and existing mirror order, adds the public cache, and
preloads only images declared by the trusted runtime Dockerfile. The Buildx
publisher uses the same mirror. Dockerfile references and full image versions
stay unchanged; missing cache entries fall back to Docker Hub. The helper
skips cache configuration and preloading on self-hosted runners, preserving their
existing daemon without a restart. It rejects unsupported hosted environments
and does not configure developer machines. See the [runner input and access
requirements](reusable-lifecycle.md).
See [Google's cache contract](https://docs.cloud.google.com/artifact-registry/docs/pull-cached-dockerhub-images)
and [BuildKit registry mirrors](https://docs.docker.com/build/buildkit/configure/).

Run the [actual-image verifier](https://github.com/php-fast-forward/changelog/blob/main/tests/Container/verify.md) after building. It
uses disposable Git repositories, synthetic credentials and network-disabled
containers. Native unit/coverage checks still run on Linux and Windows. Linux
CI also invokes a real local Docker action and checks its runner outputs.

`composer test:packaging` verifies the archive's exact public Action inventory,
Docker context and skill bytes, then installs that extracted payload in local
and global Composer consumers. This proves distribution separately from the
runner's remote Action download/build path. Validate that path with a native
Linux job using `php-fast-forward/changelog/.github/actions/check@<reviewed-sha>`,
an exact PR checkout and read-only permissions; retain its SHA and run URL with
the review evidence. This check requires no tag, release or registry publication.

Build failures leave consumer data untouched. Operation failures retain the
existing service recovery rules; inspect the exact result and remote state
before repeating a write. Rollback restores the Dockerfile, CLI adapter,
metadata, workflows and documentation together. Pushing an image to a registry
or publishing real tags/releases requires the corresponding authorization.
The repository workflow publishes images for existing release tags; creating a
real release tag still follows the existing release approval boundary.

---

[Documentation index](index.md)
