# Historical Markdown and templates

`CHANGELOG.md` is the single published history. A release preserves existing
sections; backfill adds absent versions. Newly rendered history is readable
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
[template provenance](../src/Template/PROVENANCE.md) and
[LICENSE.keep-a-changelog](../src/Template/LICENSE.keep-a-changelog).

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
