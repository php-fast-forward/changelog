# Historical import policy

`HistoryImporterInterface::import($document, $options, $template, $tags)` returns
`HistoryImportResult` with public readonly `document`, `missingVersions` and
`currentVersion` properties. `currentVersion($tags, $tagPrefix = 'v')` resolves
the same Git baseline without fetching GitHub data or formatting history.

Tag evidence consists of `name`, `sha`, nullable `date` and nullable
`date_source`. The configured prefix must match exactly. The remaining identity
must be strict stable Semantic Versioning: numeric major, minor and patch,
optional build metadata, and no prerelease identifier. Incompatible tags are
ignored; duplicate canonical stable identities are rejected even when their
commit SHA agrees. Numeric components are compared without floating-point
conversion. Equal SemVer precedence with different build metadata uses a stable
lexical tie-break for presentation. No matching tags means a `0.0.0` baseline,
regardless of version labels already present in the Markdown document.

Import inserts only missing matching Git versions, in descending order. Existing
release objects, their relative order, descriptions, headings, dates and
provenance remain intact. The document prefix and reference footer are retained.
Imported notes remain raw Markdown or prose; unknown categories and
release-shaped headings are not recategorized or discarded. The history codec
adds semantic release boundaries when publishing these newly imported sections.

For `source=auto` or `source=github` with a known repository, matching published
GitHub releases supply notes and their UTC publication day. Draft and prerelease
records are excluded. A GitHub release cannot create a version absent from the
actual Git tag evidence. Invalid matching publication timestamps, duplicate
published records, HTTP failures and unavailable lists produce diagnostics.
`auto` does not hide an authentication or permission error by falling back to
tags. Explicit `source=github` requires a repository.

For `source=tags`, or `auto` without a repository, no GitHub request is made.
When a matching published release or meaningful release body is absent, the
section contains `TemplateInterface::missingNotes()` followed by a newline.
A known GitHub publication date keeps `date_source=github-release`, even if its
body is empty. Otherwise only an explicitly annotated tag date is retained with
`date_source=annotated-tag`; lightweight tags and commit dates produce no date or
provenance. Existing sections are not fetched or enriched merely because a new
data source is available.

The [GitHub release API](https://docs.github.com/en/rest/releases/releases#list-releases)
is the primary source for published release fields and pagination. The importer
uses `published_at`; it never substitutes the commit-related `created_at`.
