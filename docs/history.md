# Historical Markdown and templates

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

## Release reference links

When `version` promotes pending fragments into a concrete release and the
effective GitHub repository is known, it also appends missing Markdown reference
definitions for every maintained SemVer section. The oldest semantic version
links to its GitHub release/tag page; each subsequent version links to the
comparison with its semantic predecessor, independently of section order.
For `owner/repo` with the default `v` tag prefix:

```markdown
[1.1.0]: https://github.com/owner/repo/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/owner/repo/releases/tag/v1.0.0
```

Existing definitions remain authoritative wherever they occur outside fenced
or indented code. Their URLs, optional titles, casing, whitespace and line
endings are preserved byte for byte. Retained headings using `[v1.0.0]` or
`[V1.0.0]` keep that reference label; new headings use their canonical version.
The complete configured tag is URL-encoded, including namespace slashes or build
metadata. SemVer precedence includes existing prereleases; distinct build
identities with equal precedence use lexical order for a deterministic predecessor.

Without a known repository, no links are added. `format`, `backfill` and plans
without a new concrete release do not add definitions. No `Unreleased` link is
created. Constructing these URLs does not query GitHub or prove that a tag or
release has been published; publication remains a separate approved operation.
The pure `HistoryReferenceBuilder` owns this completion, while the codec keeps
parsing, rendering and exact note extraction. Publication reconstructs the same
deterministic footer when checking the approved central bytes.

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

`notes [version]` returns the exact maintained body. Without a version, it selects
the highest stable SemVer maintained in the document, independently of section
order or release dates. Unreleased and prerelease sections do not become the
default. Equal core versions with different build metadata use lexical ordering
to make the selection deterministic. The stable maintained section takes priority
over Git tags; reachable stable Git tags provide the fallback only when no
stable maintained section exists. An explicit version may select a prerelease.
`--output=notes/release.md` exports to a new project-relative file. Existing files,
the consolidated changelog and all fragment-directory paths are refused.

---

[Documentation index](index.md)
