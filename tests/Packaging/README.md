# Composer packaging checks

Run the actual local and global Composer installations in disposable consumers:

```sh
rtk proxy php tests/Packaging/verify.php
```

The harness requires PHP 8.5, Composer 2, Git and RTK on `PATH`. Composer may
download the package's public runtime dependencies. It exports the current
tracked and unignored source into a clean temporary package snapshot, then uses
a Composer path repository with `symlink: false` and an explicit `dev-main`
version. Both installs disable development dependencies, plugins and scripts.
This snapshot excludes the development checkout's ignored `vendor/` directory.

Each run provides its own process environment, HOME, Composer homes/cache, Git
identity and consumer repositories. It does not change the user's global
Composer installation or credentials. GitHub tokens are removed and the API
base points to a reserved `.invalid` domain. No GitHub operation is requested.
Synthetic Git tags and an applied version transaction exist only in the
temporary consumer repositories.

The checks cover:

- The runtime dependency graph and native local/global Composer proxies, including
  a nested autoloader that must not override Composer's selected autoloader.
- All eight public commands, lazy help/list metadata and failure isolation for a
  deliberately broken selected command.
- Consumer working-directory behavior, fragment-only defaults, ignored implicit
  PHP configuration, collision rejection and opt-in scoped commits that preserve
  unrelated staged and unstaged work.
- Read-only status/version/backfill/format plans and drift checks against local
  tag history, contribution checks, one fixture-only consolidation, exact notes
  and explicit notes output.
- Plain history and release diffs without a tracked plan file or generated
  metadata comments, private-journal cleanup, and a non-Git consumer.
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
