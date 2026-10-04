# Release transaction receipts

`CHANGELOG.md` remains the maintained release history. The generated
`.changelog/release-plan.json` records one approved transaction beside pending
fragments. It is evidence for validation and recovery, not an alternative prose
source. Publishing extracts the exact section notes from the central document
and checks the retained note hash.

## Schema one

A receipt contains exactly these fields, in canonical order:

| Field | Value |
| --- | --- |
| `id` | SHA-256 of the canonical evidence below, excluding this field |
| `schema` | Integer `1` |
| `base_sha` | Complete lowercase Git SHA-1/SHA-256 commit hash, or `null` outside Git |
| `current_version` | Stable semantic version before the transaction, retaining optional build metadata |
| `next_version` | Stable semantic release version, or `null` for maintenance |
| `impact` | `patch`, `minor`, `major`, or `null` with no next version |
| `consumed` | Relative direct fragment paths mapped to lowercase SHA-256 byte hashes |
| `historical_versions` | Unique stable historical section versions imported by this plan, retaining optional build metadata |
| `changelog_file` | Canonical relative central document path |
| `fragment_directory` | Canonical relative fragment directory path |
| `locale` | `en` or `pt-BR` |
| `template` | Selected template identifier |
| `tag_prefix` | Valid selected Git tag prefix, including an empty prefix |
| `repository` | `owner/name` or `null` |
| `before_changelog_sha256` | SHA-256 of original exact bytes, or `null` when absent |
| `changelog_contents` | Generated snapshot of the exact approved target bytes, used for transaction recovery |
| `after_changelog_sha256` | SHA-256 of approved exact central bytes |
| `notes` | Exact retained note bytes, including significant spaces and newlines |
| `notes_sha256` | SHA-256 of those exact notes |

Receipt identity uses PHP JSON encoding with `JSON_UNESCAPED_SLASHES` and the
field order above, with the `consumed` map sorted by path. Stored JSON uses that
same evidence with `id` first, `JSON_PRETTY_PRINT`, and one terminal newline.
An empty consumed map has the canonical JSON representation `[]`.

Unknown, missing or duplicate keys, malformed scalar types, unsupported schemas,
invalid hashes and mismatched IDs fail validation. Relative paths cannot contain
absolute roots, backslashes, empty segments, `.` or `..`. A consumed path must
be exactly `<fragment_directory>/<lowercase-dash-slug>.md`; nested, hidden and
reserved `AGENTS.md` names are never eligible. The central document cannot live
inside the fragment directory.

An ID proves consistency of the encoded evidence. Trusted automation must still
verify the approved workflow context and compare the central document and its
extracted notes; the receipt does not grant a central-edit waiver by itself.

## Applying and checking

`ReleaseApplier::apply(plan)` acquires the same absolute-directory resource used
by fragment creation. Before any write it verifies the approved evidence, Git
base, exact original/approved central and receipt bytes, complete fragment
inventory, and every remaining fragment hash. Fresh plans require the selected
base to equal `HEAD`. Receipt resumptions require the saved base to remain an
ancestor of `HEAD`, allowing a scoped release commit without inventing a new
release.

Before the first journal write, a fresh Git-backed release also requires the
original central bytes and presence, complete committed pending Markdown set,
regular Git file modes and every consumed byte hash to match the approved base.
The selected custom PHP template must match a regular base blob before execution
and application. Unrelated working-tree files and index entries are preserved;
there is no whole-checkout cleanliness requirement. Read-only status and fragment
previews remain available before release inputs are committed. Explicitly trusted
local templates remain supported outside Git.

The transaction first writes the approved receipt as a recovery journal, then the approved central bytes,
then re-reads the full fragment inventory and all remaining hashes before
removing only the selected direct fragments. It never selects a wildcard. For a release, a new,
modified, hidden or nested fragment blocks the transaction. Backfill/format
maintenance has no consumed fragments and preserves the entire pending inventory
without reading or validating its contents.

Both file adapters inspect the final target and every ancestor through the
injected Symfony filesystem. Any symbolic target or ancestor fails before I/O;
managed-file writes repeat that check after creating a missing parent. All
package writers cooperate through the shared directory lock. Other writers
must observe that lock to make the read/write/remove sequence a transaction.

A write/removal failure releases the lock and retains the recoverable central,
receipt and fragment state. No rollback removes or replaces newer inputs.
A failed journal write leaves the central document and fragments untouched.
A failed central write leaves the prepared journal and original document, so a
new invocation can restore the exact target snapshot without calculating another
version, date or remote import. A partially completed fragment removal can resume
from the approved central bytes and receipt.
A receipt resume preserves the original ID, date-bearing central bytes, note
bytes and complete original consumed set, including already absent files.
The planner must verify that the current central bytes match either the recorded
original hash or the approved target hash before requesting a resume. Before the
central write, every approved release fragment must still exist with its exact
hash. After that write, already consumed fragments may be absent. The generated
snapshot exists only to restore the transaction; it is never manually maintained
or used as the publishing prose source.

`apply()` returns `true` when it performs the transaction and `false` when the
approved central and receipt already match and every consumed fragment is gone.
`isApplied()` performs the evidence reads without creating a lock or changing
files, Git state, tags or releases. It returns `false` for a valid pending plan;
changed or unsafe evidence raises a diagnostic. An empty plan performs no I/O.

Human backfill/format PRs commit only the central changelog. Their generated
maintenance journals stay local for recovery. Verified bot-managed maintenance
may include generated journal evidence in its managed commit; that provenance
must be independently verified.

## Composition contracts

- `ReceiptCodecInterface::encode(array $evidence): string` and
  `decode(string $contents): ReleaseReceipt`, whose readonly `data` is validated.
- `ReleasePlanFactoryInterface::create(...)` delegates canonical identity and
  serialization to the codec.
- `ReleasePlanFactoryInterface::resume(options, receipt, changelogPath,
  currentContents, receiptPath, rawReceipt): ReleasePlan` accepts nullable original
  central bytes and restores exact receipt
  identity and the complete approved consumed map without recalculating a bump.
- `ManagedFileStoreInterface::read(string $absolutePath): ?string` returns exact
  bytes or `null` for an absent file; unsafe ancestry raises a failure.
- `ManagedFileStoreInterface::write(string $absolutePath, string $contents): void`
  performs a guarded Symfony replacement while its caller holds the shared lock.
- `ReleaseApplierInterface::apply(ReleasePlan): bool` and
  `isApplied(ReleasePlan): bool`.

Constructors perform no filesystem, process, network, clock or entropy access.
Unit tests replace all such collaborators with deterministic doubles.
