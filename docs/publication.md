# Publication from an approved commit

Publication receives the complete approved commit SHA. It never chooses a moving
branch or calculates a second release. `CHANGELOG.md` at that exact commit is
the source of the GitHub Release body. No tracked release-plan file is required.
The caller must already have publication authority for that SHA.

`PublicationServiceInterface::publish(options, approvedSha, dryRun = false)`
returns state, version, tag, SHA, URL and ordered actions. States distinguish
maintenance, dry-run, published and unchanged. Actions are `create_tag` and
`create_release`.

## Reconstruct committed evidence

Before any remote mutation, publication verifies:

- The full target SHA resolves exactly and the central document is a regular
  tracked blob. Symbolic and submodule entries fail.
- The consolidation's scalar Git commit trailers identify the source base,
  output hash and option fingerprint. Merge targets must resolve consistent
  transaction evidence matching their exact central bytes; a squash must retain
  the trailers. Missing, conflicting or ambiguous evidence fails.
- The source base is an ancestor of the approved target. Its complete pending
  Markdown inventory consists of direct canonical regular fragments.
- Every source fragment is parsed from its exact committed blob and is absent
  from the approved target. No pending Markdown remains at that target.
- Stable tags reachable from the source base establish the current version.
  The complete source fragment set establishes SemVer impact and next version.
- Notes extracted from the approved central section exactly match the complete
  canonical fragment rendering and supported history round trip. Existing
  source-base history remains unchanged. Missing reachable tagged sections
  imported during planning are taken from the reviewed approved bytes for
  `auto`/`github`, rather than fetched again during publication; `tags` retains
  its deterministic tag-derived dates and missing-note text. The central output
  hash and option fingerprint match the committed transaction.

Neither copied trailers nor a consistent hash grant publication authority.
The merged-PR workflow separately verifies repository, branches, Bot identity,
signed original head and approved merge target before invoking publication.

No unstaged central or fragment bytes are used. Built-in presentation can target
an older approved commit. A custom PHP template additionally requires that exact
checked-out HEAD, a canonical regular tracked path and unchanged trusted bytes
before execution. Maintenance creates no tag or release and must match genuine
format/backfill output while retaining every pending fragment unchanged. A new
release section with undeleted consumed fragments is an incomplete consolidation,
not maintenance.

## Remote reconciliation

The injected GitHub client reads the exact tag and release before mutation.
A conflicting tag is never moved; divergent release notes are never updated.
Annotated tags are peeled through controlled relative endpoints with cycle and
depth limits. Server-supplied object URLs are never followed.

A missing tag targets the approved SHA. A missing release uses that tag, the same
approved SHA and exact central notes, with draft, prerelease and generated-notes
flags disabled. Fresh reads confirm persisted state after each creation.
A matching tag without its release is completed on retry. Uncertain responses
are reconciled by authoritative reads; absent or conflicting results fail with
a recoverable diagnostic. Repeating a complete publication returns unchanged.

Dry-run performs proof and remote reads without POST, PATCH or DELETE.
Unit tests replace Git, filesystem, templates, factories and HTTP; they create
no real release and use no user credentials.

See [local recovery](release-receipts.md), [managed PRs](version-pull-request.md)
and [workflow contracts](github-actions.md).
