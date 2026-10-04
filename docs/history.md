# Historical Markdown and templates

`CHANGELOG.md` is the single published history. Collecting a release preserves
its existing sections; backfill adds only versions that are absent. Imported
notes may contain prose, unfamiliar categories, links and fenced code. The
history codec does not infer a category or discard prose to force it into a
Keep a Changelog list.

## Codec API

`HistoryCodecInterface` is a pure boundary with no filesystem, network or clock:

```php
parse(string $markdown): HistoryDocument;
render(HistoryDocument $document, TemplateInterface $template, bool $preservePresentation = false): string;
notes(HistoryDocument $document, string $version): string;
```

A document exposes `getReleases()`, `getPrefix()`, `getReferences()` and
`getRelease($version)`. `withReleases($releases)` retains the original prefix and
raw reference footer while replacing the ordered release list. Reference
footers retain all subsequent text, including custom boilerplate.

A release exposes its canonical version, optional calendar date, optional
`dateSource`, exact body, original heading and structural ending. Factories
construct these values. The release factory removes one leading `v` or `V`
from a numeric version and validates strict Semantic Versioning, including
prereleases and build metadata. `unreleased` is the reserved pending identity.
Duplicate canonical identities and invalid calendar dates fail before output.
The codec never invents a missing date or provenance; callers obtain those
values from the documented release-source policy.

`notes()` returns the stored body bytes, without its outer release heading,
release delimiters or document reference footer. It retains category headings,
prose, inline links and fenced code. An absent version produces a diagnostic.

## Preservation and reformatting

The parser accepts the English and Brazilian Portuguese Keep a Changelog
headings. It preserves original line endings, inline heading links and yanked
annotations. Fenced code never creates release or reference boundaries.

Use `render(..., preservePresentation: true)` for incremental publication.
Existing sections retain their original bytes. New sections receive a template
heading and semantic markers; a missing final body newline is completed so the
closing delimiter remains a separate Markdown line.
`body_length` includes this canonical final newline. Already maintained sections
retain their original presentation bytes during incremental rendering.

Use `render(..., preservePresentation: false)` for explicit reformatting. The
codec replaces recognized introduction, release and category structure using
the selected template. It preserves descriptions, unknown headings, arbitrary
prose, dates, yanked annotations, references and footer text. Changing the locale
does not translate change descriptions. Unrecognized introduction text remains
untouched. Invalid markers and an unclosed code fence produce diagnostics
before a reformatted document is returned.

Semantic markers make custom headings reversible and keep release-shaped
headings inside imported notes from becoming additional releases:

```markdown
<!-- fast-forward-changelog:release {"version":"1.2.3","date":"2026-10-03","date_source":"github-release","body_length":95} -->
## [1.2.3] - 2026-10-03
<!-- fast-forward-changelog:category fixed -->
### Fixed

- Preserve the original description.
<!-- fast-forward-changelog:end-release -->
```

A marked section requires one level-two heading and its end delimiter.
`date` and `date_source` may be `null`; existing unmarked sections keep unknown
provenance. Category markers use the stable identifiers `added`, `changed`,
`deprecated`, `removed`, `fixed` and `security` followed by one level-three
heading. Generated introductions are bounded by
`fast-forward-changelog:introduction` comments. Treat these comments as reserved
structural metadata when editing a generated document.

## Presentation templates

`TemplateFactoryInterface::create(string $locale = 'en', array $overrides = [])`
accepts `en` or `pt-BR`. The default presentation is Keep a Changelog. The
factory validates the complete override shape before constructing a template.
Unknown settings, unsupported locales and incompatible heading levels fail
early.

| Setting | Value |
| --- | --- |
| `introduction` | Nonempty raw Markdown string |
| `release_heading` | One `## ` heading containing `{version}` |
| `release_heading_dated` | One `## ` heading containing `{version}` and `{date}` |
| `unreleased_heading` | One `## ` heading |
| `category_headings` | Partial map from the six canonical identifiers to one-line `### ` headings |
| `no_notes` | Nonempty localized message for a release whose notes are unavailable |

For example, an explicitly selected trusted PHP template may return:

```php
<?php

return [
    'release_heading' => '## Release {version}',
    'release_heading_dated' => '## Release {version} on {date}',
    'category_headings' => ['fixed' => '### Corrections'],
];
```

The CLI composition loads this array through `fast-forward/config` only when a
template file is explicitly selected. PHP templates execute PHP and therefore
must come from a trusted base in workflows that have publication privileges.
The codec and template factory themselves perform no file loading. Customization
changes presentation without registering plugins or replacing the history.

## Upstream convention

The default follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).
Portuguese category names follow the
[official Brazilian Portuguese principles](https://keepachangelog.com/pt-BR/1.1.0/).
The Portuguese introduction and missing-note messages are package-authored
translations. The verified source revision and upstream MIT notice are included
in [template provenance](../src/Template/PROVENANCE.md) and
[LICENSE.keep-a-changelog](../src/Template/LICENSE.keep-a-changelog).
## History framing and exact notes

Generated release metadata records `body_length`, measured in rendered body
bytes. The codec reads the entire body before checking its closing
delimiter. Markdown inside imported notes may therefore contain release-like
headings or delimiter comments without becoming another release. Invalid lengths
or closing markers are diagnosed before maintenance writes. Older generated
sections without this field remain readable when their framing is unambiguous.

`notes [version]` selects the saved pending release, then stable tags reachable
from the current `HEAD`. Tags on unrelated branches cannot change its default.
`--output=notes/release.md` exports the exact body to a **new** project-relative
file. Existing files, the consolidated changelog, and the entire fragment directory
are refused. Omit `--output` to send the exact bytes to stdout.
