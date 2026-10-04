# Publication from an approved commit

Publication requires an explicit complete approved commit SHA. It never selects a
moving branch or calculates another release. `CHANGELOG.md` at that commit is the
only source of the GitHub Release body. The generated receipt records evidence;
its ID proves internal consistency and does not grant publication authority.
The caller must supply the SHA approved by the trusted release workflow.

`PublicationServiceInterface::publish(options, approvedSha, dryRun = false)`
returns an immutable `PublicationResult` with `state`, `version`, `tag`, `sha`,
`url` and ordered `actions`. `summary()` exposes those exact machine fields.

| State | Meaning |
| --- | --- |
| `maintenance` | Receipt has no new version; no remote requests, tag or release |
| `dry-run` | Local proof and remote reads succeeded; actions are proposed only |
| `published` | Missing remote objects were requested and their persisted state verified |
| `unchanged` | Exact matching tag and published release already exist |

The action names are `create_tag` and `create_release`. A dry run reports what
would be created. A normal run records creation attempts, including an uncertain
response that was subsequently recovered through a successful authoritative read.

## Commit evidence

Before any remote mutation, publication verifies all of the following:

- The supplied full SHA resolves to that exact commit. Receipt and central
  changelog are regular tracked blobs, including their modes; symbolic and
  submodule entries are rejected.
- Receipt settings agree with the explicit options. The central document equals
  the generated target snapshot and its SHA-256 hash.
- A release receipt has an explicit repository and Git base. That base is an
  ancestor of the approved commit, and its central bytes match the original
  changelog hash.
- The receipt consumes the complete Markdown fragment inventory at its base,
  excluding only root `AGENTS.md`. Nested, hidden, noncanonical or symbolic
  fragments fail. Every original fragment hash is checked against its exact base
  blob, then its schema is parsed again.
- No pending Markdown fragment remains at the approved consolidation commit,
  including newly added, nested or symbolic files. Every declared consumed path
  is also checked absent. A stale consolidation is rejected instead of publishing
  a partial release. The workflow must target its exact approved merge SHA.
- The current version comes from stable tags whose peeled commit is reachable
  from the receipt base. Future/unrelated tags are excluded. Impact and next
  version are recomputed from all consumed fragments and must match the receipt.
  Existing stable versions may retain build metadata; the new resolved version
  uses its numeric semantic version.
- The approved version's exact notes are extracted from `CHANGELOG.md`, compared
  with the receipt bytes/hash, and compared with the canonical complete fragment
  rendering after an isolated history round trip.

Publication never reads unstaged fragment or central document bytes. A built-in
presentation can publish an explicit older approved SHA independently of the
current checkout. An explicitly selected PHP template additionally requires
`HEAD` to equal the approved SHA, a canonical regular tracked template path, and
exact equality between its committed and guarded working file bytes before
execution. Privileged callers must select trusted approved template code.

Maintenance verifies its committed receipt and central snapshot, then returns
without publishing a tag or release. It does not resolve notes or consume pending
fragments.

## Remote reconciliation

The authenticated `GitHubClientInterface` is injected. Constructors do not load
credentials, invoke Git commands, read files or send HTTP. The service first reads
the exact tag reference and release by tag, and rejects divergence before creating
anything. Lightweight refs are checked directly. Annotated objects are peeled
through controlled relative endpoints, with cycle detection and a maximum of
8 tag objects. Server-supplied object URLs are never followed.

A missing tag is created with `ref = refs/tags/<tag>` and the approved SHA. There
is no update, force push, delete or tag movement. After creation, a fresh read must
confirm its commit. A missing release uses the approved SHA as `target_commitish`,
its tag as the title, and exact extracted central notes as `body`, with `draft`,
`prerelease` and `generate_release_notes` all false. The tag is checked again before
and after release creation. These payloads follow GitHub's
[reference API](https://docs.github.com/en/rest/git/refs?apiVersion=2026-03-10),
[tag-object API](https://docs.github.com/en/rest/git/tags?apiVersion=2026-03-10) and
[release API](https://docs.github.com/en/rest/releases/releases?apiVersion=2026-03-10).

An existing tag must point to the approved commit. An existing release must have
the exact tag and body and be a stable published release. Divergent objects are
never edited. A tag without its release is completed safely on retry. A lost POST
response or concurrent creation is reconciled by reading the persisted object;
an absent or conflicting result fails with a recoverable diagnostic. A created
tag is retained when release creation fails. Repeating a complete publication
returns `unchanged` without further mutations.

A dry run performs the same local proof and remote GET requests, returns proposed
actions and never sends POST, PATCH or DELETE. Unit tests replace all filesystem,
Git, template, factory and HTTP boundaries with deterministic doubles; no real
release, credential or user workspace is used.

## Composition

Register the following aliases:

- `PublicationServiceInterface` → `PublicationService`.
- `PublicationEvidenceValidatorInterface` → `PublicationEvidenceValidator`.
- `PublicationEvidenceFactoryInterface` → `PublicationEvidenceFactory`.
- `PublicationResultFactoryInterface` → `PublicationResultFactory`.

The service constructor is `(PublicationEvidenceValidatorInterface,
GitHubClientInterface, PublicationResultFactoryInterface,
ReleaseExceptionFactoryInterface)`.

The evidence validator constructor is `(GitRepositoryInterface,
ReceiptCodecInterface, HistoryCodecInterface, ChangesetParserInterface,
NextVersionResolverInterface, HistoryImporterInterface,
ReleaseNotesRendererInterface, TemplateResolverInterface,
PackagePathResolverInterface, ManagedFileStoreInterface,
HistoryReleaseFactoryInterface, HistoryDocumentFactoryInterface,
PublicationEvidenceFactoryInterface, ReleaseExceptionFactoryInterface)`.

`GitRepositoryInterface::filesAt(directory, reference, relativePath)` supplies
complete relative tree entries with `path` and `mode`. `tags()` supplies peeled
commit SHAs. All Git access here is read-only.
