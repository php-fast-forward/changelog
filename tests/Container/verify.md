# verify.php

This integration fixture checks the actual installed CLI image, including its
entrypoint, production dependency graph, command registration, input/exit
contracts, contribution checks, GitHub output records and exact version notes.
It does not belong to the unit suite and does not replace native unit coverage.

Requires PHP 8.5, Git, Docker and a caller-owned built image. The selected Docker
context must use a local Unix endpoint; remote contexts are refused. The driver
uses structured process arguments and requires no package or RTK dependency.

```sh
docker build -t fast-forward-changelog:ci .
php tests/Container/verify.php fast-forward-changelog:ci
```

The optional first argument is the image name; the default is
`fast-forward-changelog:ci`. Fixtures are created below the system temporary
directory with the prefix `changelog-container-`. Git configuration and HOME
are isolated for child processes. Each container has networking disabled,
synthetic credentials and a reserved invalid API endpoint. The fixture mounts
only its own temporary directory and uses an explicitly local Docker endpoint.

The driver writes synthetic repositories, a fixture-only Git tag, captured
command output and Action output records. It prints assertion/command counts
and the retained fixture path. Containers are removed after each invocation;
the image and fixture remain available for inspection. No real GitHub mutation,
tag publication, registry push or host configuration change is performed.

On failure, inspect the reported stdout/stderr and retained fixture, repair the
image or contract, rebuild, and rerun with a new disposable directory. Never use
a production checkout as the fixture. CI also executes the real Docker action
metadata and checks its declared outputs through the GitHub runner.
