# Composer packaging checks

Run the actual local and global Composer installations in disposable consumers:

```sh
rtk proxy php tests/Packaging/verify.php
```

The harness requires PHP 8.5, Composer 2, Git, tar and RTK on `PATH` for local runs.
The dedicated CI job sets `CHANGELOG_PACKAGING_DIRECT=1` to execute its structured
arguments directly on the disposable runner, without an RTK prerequisite. Composer may
download the package's public runtime dependencies. It exports the current
tracked and unignored source into a synthetic Git repository, creates an archive
using the candidate `.gitattributes`, then extracts that archive as the package
for a Composer path repository with `symlink: false` and an explicit `dev-main`
version. Both installs disable development dependencies, plugins and scripts,
and must mirror the archive payload exactly. Checkout dependencies and caches
never enter that payload.

`CHANGELOG_PACKAGING_DEPENDENCIES=highest` is the default fresh resolution.
Set it to `lowest` to exercise the minimum stable runtime dependency graph with
`composer update --prefer-lowest --prefer-stable` in both disposable installations.
The development checkout keeps its lock; neither mode updates that file. CI runs
both bounds on the supported PHP 8.5 runtime.

Each run provides its own process environment, HOME, Composer homes/cache, Git
identity and consumer repositories. It does not change the user's global
Composer installation or credentials. GitHub tokens are removed and the API
base points to a reserved `.invalid` domain. No GitHub operation is requested.
Synthetic Git tags and an applied version transaction exist only in the
temporary consumer repositories.

The checks cover:

- A synthetic Git archive retaining exactly the six public Docker Action
  metadata files under `.github`, their shared root Docker build context and
  every canonical skill file byte for byte. Repository workflows, templates,
  funding and internal Action helpers stay out of that archive, as do host
  configuration and development files.
- The shipped PHP contribution example with success, missing input, collision
  and I/O failure, plus the skill's inline CLI commands and read-only preview.
- The runtime dependency graph and native local/global Composer proxies, including
  a nested autoloader that must not override Composer's selected autoloader.
- A synthetic autoload warning retained on stderr while machine output remains a
  single JSON object, even when PHP initially enables display_errors on stdout.
- All eight contributor/maintainer commands and the `github` automation entrypoint,
  lazy help/list metadata and failure isolation for a
  deliberately broken selected command.
- Consumer working-directory behavior, fragment-only defaults, ignored implicit
  PHP configuration, collision rejection and opt-in scoped commits that preserve
  unrelated staged and unstaged work.
- Read-only status/version/backfill/format plans and drift checks against local
  tag history, contribution checks, one fixture-only consolidation, exact notes
  and explicit notes output.
- Plain history and release diffs without a tracked plan file or generated
  metadata comments, private-journal cleanup, and a non-Git consumer.
- Legacy pending descriptions promoted once into the concrete version, with no
  retained Unreleased heading or duplicate fix category.
- A real fixture consolidation commit using the CLI's exact commit message and
  read-only publication validation from committed Git blobs, without remote writes.

The final output reports assertions, executed commands and the retained fixture
path. Command stdout/stderr logs remain there for diagnosis. An optional first
argument selects another package checkout. An optional second argument reuses
only a `composer-cache` directory created by an earlier packaging run under the
system temporary directory; the user's normal Composer cache is rejected.

These integration checks live outside the unit suite and its source coverage
gate. Unit tests continue to replace filesystem, process, network and environment
collaborators with doubles.

Archive checks do not execute GitHub's remote Action downloader or build an
image. A native Linux smoke must separately reference the reviewed commit as
`php-fast-forward/changelog/.github/actions/check@<reviewed-sha>`, validate the
exact PR checkout and retain its run evidence. Local Action invocation after a
Git checkout exercises a different distribution path.
