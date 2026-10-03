# Version planning primitives

The next release uses the greatest effective `type` among pending fragments.
Category defaults are materialized in each `Changeset::type`; an explicit type
may be lower or higher than the category default. There is no second impact
field in the value object and no CLI/Action-specific SemVer implementation.

| Category | Default effective type |
| --- | --- |
| `added` | `minor` |
| `changed` | `minor` |
| `deprecated` | `minor` |
| `removed` | `major` |
| `fixed` | `patch` |
| `security` | `patch` |

`VersionImpactResolverInterface::resolve(array $changesets)` returns the
maximum effective impact or `null` for an empty collection. Fragment identity,
PR, author or description never causes a fragment to disappear from aggregation.
The result is independent of input order.

`NextVersionResolverInterface::resolve(string $currentVersion, array $changesets)`
returns a `VersionResolution` containing `nextVersion`, `impact` and diagnostics.
It validates a SemVer numeric core, accepts an optional leading `v`/`V` and
build metadata, and returns an unprefixed core for the next release. Build
metadata does not enter the next version. Prerelease inputs are rejected until
promotion has a specified public policy. Numeric components are strings and
are incremented without machine-integer overflow.

| Current | Effective impact | Next |
| --- | --- | --- |
| `1.2.3` | patch | `1.2.4` |
| `v1.2.3+build.7` | minor | `1.3.0` |
| `1.2.3` | major | `2.0.0` |
| `0.0.0` | minor | `0.1.0` |

A current version is provided by the shared release planner, rather than by
reading a version property from an npm or Composer manifest. A repository with
no prior supported tag/documented version starts from `0.0.0`; its fragments
still determine the first result using the same table. An empty collection is
not a version operation: the numeric resolver reports that at least one
fragment is required, while status/history maintenance can report an unchanged
state through the planner.

Manual impact requests must be combined with the pending effective maximum;
they must never silently lower a greater fragment impact. Applying a plan,
historical backfill and publishing tags/releases are separate responsibilities.
These primitives perform no Git, filesystem, clock, process or network access.
