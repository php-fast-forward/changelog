# Historical Markdown and templates

Use this guide to adopt an existing changelog, import missing releases or change
presentation. Preview from the consumer project before applying maintenance:

```sh
cpx --skip-local fast-forward/changelog backfill --dry-run --source=tags
cpx --skip-local fast-forward/changelog format --dry-run --locale=pt-BR
```

Backfill adds missing tagged versions; format changes recognized headings while
preserving descriptions. Review the proposed output before applying an
authorized operation. [Transactions and recovery](release-receipts.md) explain
application; the contracts below describe preservation, links and templates.

`CHANGELOG.md` is the single published history. A release preserves published
sections and promotes legacy `Unreleased` descriptions into the new version,
removing the pending heading and combining matching categories. Backfill and
format retain pending content; new contributions use independent fragments.
Backfill adds absent versions. Newly rendered history is readable
Markdown: headings, descriptions and links, with no generated fragment JSON,
category markers, release delimiters or introduction comments.

## Codec

`HistoryCodecInterface` is pure: it performs no filesystem, network or clock
access. It parses documents, renders them with the selected template and extracts
a version's exact body without its outer heading or document reference footer.
A document retains its prefix, ordered releases and raw reference footer.

A release carries its semantic version, optional calendar date, body and original
presentation. Missing dates are not invented. Default release/category headings
follow English or Brazilian Portuguese Keep a Changelog presentation. Explicit
templates can supply readable custom headings.

Incremental rendering preserves existing presentation. Explicit formatting
changes recognized introduction and structural headings, leaving descriptions,
unknown headings, code fences, dates, yanked annotations and references intact.
Changing locale does not translate descriptions.

Existing Changesets headings such as `## 0.5.1` are recognized as release
boundaries, including valid prerelease/build versions. Incremental preparation
preserves their exact headings and
notes, while new releases use the selected template. Additional text on a bare
version heading is not part of this import grammar. Multiline fragment paragraphs
remain indented within their list item without adding spaces to empty separators;
authored Markdown hard breaks remain unchanged.

Descriptions that begin with a Markdown list keep their own `-`, `*`, `+` or
ordered markers and relative nesting. Up to three leading spaces that indent
the first authored item are removed independently from each item's available outer
indent, so entries cannot become children of an unrelated fragment. Stored fragment
descriptions stay unchanged.
Only structural indentation tabs are expanded before list padding is added.
Literal tabs inside indented and fenced code, including Makefile recipes,
remain unchanged; a tab crossing the structural boundary retains equivalent
content columns.
The renderer does not add a
second outer bullet. With a PR or issue reference, each single-line principal
prose item receives the same inline reference. A multiline item keeps its reference
in an indented paragraph after its content, outside code spans, HTML comments
and fenced examples; nested content retains its authored bytes. Ordered lists
are separated from surrounding fragments so their starting number stays a list.
Multiline fragments end with a blank block boundary before the next change,
preventing a terminal HTML block from consuming another entry.
An item containing only indented code or link definitions also receives a separate
reference paragraph, preserving the command, link destination and optional title.
Terminal link definitions remain active and distinct from the changelog's
version-reference footer, including when root paragraphs have ended the list.
Definitions are nested only when their owning item can contain them; metadata
is not required to preserve those links.
The same outer-indentation adjustment applies to lists without references.

New fragments require an explicit closing delimiter for every code fence,
including fences that Markdown could end implicitly at the next list item.
Validation also rejects HTML comments left open outside code blocks or complete
inline code spans. Literal text inside link/image titles or HTML attributes is
not treated as a comment opener. Direct rendering refuses to add references to
unclosed fences or comments. In existing history, a list-contained fence
ends when its container ends, so a dedented release heading remains visible.
Quoted code uses the same container rules. Fence-looking text inside a closed
HTML comment remains comment content instead of opening a code block.
The optional space after `>` may vary between lines. Historical parsing,
formatting, reference discovery and migration of older pending notes share these
container and comment rules.
In older history, a contained HTML comment also ends with its list or quote,
preserving subsequent release headings without changing the original bytes.
A fence can interrupt paragraph text. An earlier inline comment or backtick does
not hide that fence from validation; the fenced block must still close explicitly.
Leading indentation and continuation paragraphs retain the same list-content
boundary during validation, rendering and historical parsing.
CommonMark classifies the complete source before presentation. Raw HTML owns its
literal fence examples, and inline code spans cannot cross sibling items. The
library edits source slices rather than converting Markdown through an HTML renderer.
Raw HTML must leave the next release heading visible; delivery references are
refused when HTML would consume their actual insertion position.

## Release reference links

When `version` promotes pending fragments into a concrete release and the
effective GitHub repository is known, it inserts missing Markdown reference
definitions for reachable stable tags and the newly planned release. A version
with no earlier reachable stable tag links to its GitHub release/tag page;
otherwise it compares with the preceding reachable stable tag, independently
of section order. Untagged historical sections do not gain new definitions.
New definitions follow the maintained section order: a new latest release goes
before the older version links, and a missing intermediate version goes before
its next retained footer reference. Existing definitions and unrelated links keep
their relative order; their destinations are never rewritten.
Definitions shown inside fenced list or quote examples cannot replace a real
release footer link.
For `owner/repo` with the default `v` tag prefix:

```markdown
[1.1.0]: https://github.com/owner/repo/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/owner/repo/releases/tag/v1.0.0
```

Existing active Markdown definitions remain authoritative; examples inside code
or HTML comments do not define links. Their URLs, optional titles, casing, whitespace and line
endings are preserved byte for byte. Retained headings using `[v1.0.0]` or
`[V1.0.0]` keep that reference label; new headings use their canonical version.
The complete configured tag is URL-encoded, including namespace slashes or build
metadata. Prerelease tags do not establish a stable comparison base; distinct
stable build identities with equal precedence use lexical order for a
deterministic predecessor.

For example, tag `v1.7.0`, untagged history `1.7.1` and a planned `1.7.2`
produce a comparison from `v1.7.0` to `v1.7.2`. No new `[1.7.1]` definition
is added. An existing definition for that section remains unchanged. The new
release link is prospective: its target tag is created only during publication.

Without a known repository, no links are added. `format`, `backfill` and plans
without a new concrete release do not add definitions. No `Unreleased` link is
created. Planning supplies tags already proven ancestral to the source base;
constructing URLs does not query GitHub or publish a tag or release.

The pure `HistoryReferenceBuilder` owns completion. Its optional tag context
distinguishes a known empty tag inventory from an omitted context; callers that
omit it retain the original history-only completion behavior. Planning and
publication always supply the known inventory. Publication checks exact
reconstructions, including the older completion formats for already approved
commits; it does not accept arbitrary changes to footer destinations.

## Entry references and attribution

[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) calls for readable
changes, grouped categories and linkable versions and sections. It does not
require a pull-request reference, prescribe its position or parentheses, or
require author names. The following is this library's presentation convention,
compatible with those principles rather than an additional rule of the standard:

```markdown
- Adds missing release comparison links. ([#39](https://github.com/php-fast-forward/changelog/pull/39))
```

The description comes first; optional issue/PR references follow in parentheses.
The renderer emits inline links when the repository is known and plain numeric
references otherwise. Existing reference-style links such as `([#39])` with a
matching footer definition have the same visible result and remain unchanged.
The PR is the primary delivery reference; an explicitly supplied issue is
additional problem context. The visible label stays short rather than exposing
the complete URL. When no PR or explicitly supplied issue is known, retain only
the description. Do not infer a commit reference or generate commit links in the
footer. Existing historical links remain preserved during consolidation.
Multiline descriptions retain their paragraphs, with references on the final
indented paragraph of the same list item. Authored lists instead follow the
per-item reference placement described above.

After changing the selected runtime, regenerate an open version PR before
publication if its notes still use an older rendering format. Publication
reconstructs the exact approved output; it does not silently rewrite an approved
changelog or loosen that comparison. Published history remains unchanged.

Ordinary fragments omit `author`. The renderer adds an author only when that
optional metadata was explicitly supplied; there is no separate global author
visibility flag. Existing historical attributions are preserved rather than
removed by release preparation. Contribution checks require neither author
nor PR metadata, and a missing PR number does not require a later edit or commit.

## Resolve missing pull-request references

Version preparation can resolve a fragment's missing `pull_request` from Git and
GitHub evidence. The local CLI keeps this disabled by default; opt in with
`--resolve-pull-requests`. The version/publish Actions and reusable lifecycle
enable `resolve-pull-requests: true` by default. Existing callers need no new
field; set it to `false` consistently when keeping references entirely explicit.

The resolver matches the fragment's exact committed bytes and canonical regular
path against first-parent Git history at the selected base. It accepts a merged
PR only after checking its repository and commit ancestry, including independent
GitHub merge identity. It then requires the complete PR file delta to identify
the exact accepted Git blob and verifies those bytes at the original PR head.
That head's byte-introducing commit must belong to the complete PR commit delta;
a pre-existing base commit does not establish implementation by this PR.
The head's first-parent byte introduction must be its own change: importing the
same bytes from a merge side parent does not establish implementation provenance.
Titles, branch names and commit prose do not establish that origin.
Explicit PR, issue and author metadata remains authoritative.
Discovery adds no author, issue or commit link.
Local previews compare the accepted fragment hashes with the committed blobs;
publication reconstructs from the immutable source base independently of the
current worktree.

Resolved references enrich values in memory before rendering; source fragments
are not rewritten to add metadata. Normal version consolidation still consumes
its validated fragments. A complete lookup with no qualifying merged PR leaves
the description alone. Outside Git, or without committed matching bytes, origin
lookup adds no reference and does not relax the normal release-input checks.
Unavailable, incomplete or ambiguous API evidence fails preparation before any
journal or managed-file write.

### Stacked implementation PRs

An automatically inferred reference identifies a proved implementation/review
PR, rather than an umbrella PR that merely integrates other contributions.
GitHub can report a shared merge SHA for several stacked PRs and associate an
implementation commit only with the umbrella PR. Neither association nor shared
merge ancestry alone establishes the implementation. The resolver does not scan
every closed PR or guess a replacement number. If an associated merged PR cannot
prove its own exact-byte change, preparation fails with an explicit diagnostic.
Unavailable original head objects also fail rather than weaken the proof.

When the implementation PR is known, record `pull_request: 26` (using the actual
reviewed number) in that fragment. This explicit route bypasses automatic origin
discovery. Corrections to already inherited fragments still require verified
maintenance authorization and a new validated maintenance note. Regenerate and
review the version PR; do not edit its generated history manually. Published
releases remain immutable.

Custom Git adapters enabling automatic resolution must implement both
`GitFragmentOriginReaderInterface` and `GitFragmentProvenanceReaderInterface`.
The former retains its first-parent integration-origin contract; the latter
adds the original-head implementation proof. PR file evidence is paginated and
must match the full API changed-file count, with a maximum of 3000 files. The
commit list must match the full API commit count and remain within the endpoint's
250-commit limit. Truncation or exceeding either bound fails with the explicit
metadata diagnostic; partial results never authorize attribution.

`--source=tags` controls historical release-note imports independently of this
setting. It does not disable PR-origin API reads when resolution is enabled.
The codec and Markdown renderer remain pure; planning and publication supply
the verified reference before calling them.

The setting participates in the transaction fingerprint. Publication recomputes
the same Git/API evidence and requires the approved central bytes to match
exactly. A changed or unprovable association blocks publication; regenerate and
review the version PR rather than editing approved output or moving a tag.
Likewise, enabling resolution for an already prepared PR requires regeneration
with that setting before approval. An interrupted local transaction resumes its
saved exact output without another origin lookup, under the same validated
settings and recovery journal.

## Plain Markdown boundaries

A level-two release heading outside a fenced code block starts a new release.
Unindented terminal reference definitions form the document footer. Markdown
that looks exactly like either boundary cannot simultaneously remain unmodified
and have a different structural meaning. New or imported notes with an ambiguous
boundary are rejected explicitly; put literal examples inside fenced code.
Other prose, links and fenced code remain intact.

Legacy generated comments remain supported when reading earlier history. Their
length-delimited bodies can be recovered faithfully. New rendering emits no
technical markers, and explicit formatting migrates parsed legacy presentation
to plain Markdown.

For example:

```markdown
## [1.2.3] - 2026-10-03

### Fixed

- Preserve meaningful Markdown in release descriptions.
```

## Templates

`TemplateFactoryInterface::create(locale = 'en', overrides = [])` accepts
`en` or `pt-BR`. Settings include introduction, release/unreleased headings,
category headings and the missing-notes message. Release headings require level
two and `{version}`; dated headings also require `{date}`. Category headings
require level three. Consecutive version/date placeholders need a literal
delimiter outside the SemVer alphabet, such as a space, bracket or colon.
Adjacent placeholders and separators consisting only of letters, digits, dots,
hyphens or plus signs fail early because the rendered heading would be ambiguous.
Unknown settings and incompatible shapes also fail early. An Unreleased heading
cannot collide with a concrete selected-template release or built-in release
heading.

Category headings must remain distinct after terminal spaces and tabs are
removed. Those terminal characters are normalized before rendering so a custom
heading cannot collide with another category or change identity on a formatting
round trip. Ambiguous overrides fail before any history is written.

Keep a custom template matching the headings already present until a reviewed
migration changes those headings. Switching to a different custom syntax does
not reinterpret old release-shaped level-two headings as introductory prose:
unsupported headings fail explicitly before collection or publication can
backfill duplicate history. Fence or nest version-bearing Markdown examples that
are prose, rather than top-level release structure.

An explicitly selected trusted PHP template may return:

```php
<?php
return [
    'release_heading' => '## Release {version}',
    'release_heading_dated' => '## Release {version} on {date}',
    'category_headings' => ['fixed' => '### Corrections'],
];
```

The template is executable PHP and must come from the approved tracked base in
privileged workflows. The codec/template factory themselves load no files.

The default follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/)
and its [Brazilian Portuguese presentation](https://keepachangelog.com/pt-BR/1.1.0/).
Verified provenance and the upstream MIT notice remain in
[template provenance](https://github.com/php-fast-forward/changelog/blob/main/src/Template/PROVENANCE.md) and
[LICENSE.keep-a-changelog](https://github.com/php-fast-forward/changelog/blob/main/src/Template/LICENSE.keep-a-changelog).

## Notes

Use `notes [version]` for an exact maintained body, or `--output` for a new export
file. Version selection and file protection are specified in the
[CLI reference](cli-fragments.md).

---

[Documentation index](index.md)
