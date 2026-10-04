# Fragment schema

Each change occupies a distinct Markdown file directly inside `.changelog/`.
The filename is its stable identity, including `.md`, and must match
`^[a-z0-9]+(?:-[a-z0-9]+)*\.md$`. Examples: `fix-parser.md` and
`adjust-dependency-a1b2c3d4.md`. Creating the same name twice fails without
replacing the first fragment. A pull request may contribute several fragments.
Root-level `AGENTS.md` is reserved for instructions; hidden and nested Markdown
files are invalid fragments and remain visible to validation. Symbolic links,
including broken links, are discovered as candidates and rejected before reads.

Newly serialized fragments use this canonical schema:

```markdown
---
category: changed
type: patch
issue: 123
pull_request: 456
author: "mentordosnerds"
---

Updates a dependency while preserving the public API.
```

`category` and effective `type` are always serialized. `issue`, `pull_request`
and `author` are optional and omitted when unavailable. A number for a pull
request that has not been opened is not required to create a fragment.

| Field | Contract |
| --- | --- |
| `category` | `added`, `changed`, `deprecated`, `removed`, `fixed`, `security` |
| `type` | `major`, `minor`, `patch`; the effective persisted release impact |
| `issue` | Positive integer within PHP's integer range, or `null` |
| `pull_request` | Positive integer within PHP's integer range, or `null` |
| `author` | GitHub login, including the `[bot]` suffix when applicable, or `null` |

Category and impact are independent. A supplied type overrides the category
in either direction: `category: removed` with `type: patch` is valid. The
creation defaults are `minor` for added/changed/deprecated, `major` for removed,
and `patch` for fixed/security. Persisting the effective type prevents later
policy changes from retroactively changing a written fragment.

The author is stored without a leading `@`. Usernames must not start/end with
a hyphen or contain consecutive hyphens; bot suffixes are accepted.

## Restricted frontmatter

This frontmatter is a small scalar format; it does not require a YAML parser.
Each metadata line has one supported lowercase key followed by `:` and one
scalar value. Plain values or simple single/double quotes are accepted. Quoted
values cannot contain quote escapes or backslash escapes. Nested mappings,
arrays, block scalars, comments in values, duplicate keys and unknown keys are
rejected with diagnostics. Arbitrary metadata is never silently discarded.
References use unsigned decimal digits without signs, leading zeroes or
scientific notation. Delimiters are exactly `---` on their own lines.

The Markdown body must contain non-comment text. Parsing normalizes CRLF/CR
to LF and removes only blank boundary lines; it preserves indentation, hard
break spaces, interior blank lines, comments and all other description bytes.
Serialization adds one final newline. The parser does not render or translate
the author's description.

## Migration of existing fragments

Historical fragments remain readable:

- `version` is accepted as a migration-only impact field and normalized to
  `type` in memory. If both fields exist with different values, parsing fails.
  If both agree, serialization emits only `type`.
- A historical fragment without either impact field uses its category default;
  its next serialization materializes that effective type. New writers always
  persist `type`.
- The legacy `pull-request` spelling becomes `pull_request`. Supplying both
  spellings is a duplicate metadata error.
- A leading `@` on an old author is removed during parsing.

This compatibility path does not alter files during validation or status.
Canonical rewriting happens only when an explicitly requested operation writes
that fragment; no duplicate impact field is maintained.

## I/O and concurrent operations

The store receives absolute paths from the composition root, validates every
ancestor against symbolic links, and rejects relative paths, parent traversal,
NUL bytes and invalid write filenames before mutation. The caller supplies the
physical absolute project root; installation directory and HOME are not inputs
to fragment path selection.

`ChangesetStoreInterface::lockResource($absoluteDirectory)` identifies the
shared directory transaction. Fragment creation acquires that resource before
checking existence and writing, then releases it on every result or exception.
Release application must hold the same resource across planning, document
writing and removal. The protection coordinates cooperating package operations;
external writers must also honor the resource to participate in serialization.

`remove($paths)` preflights the entire explicit selection before any deletion,
rejects reserved/invalid/symbolic paths, deduplicates repeated paths and leaves
missing paths as an idempotent no-op. It does not remove a directory or discover
extra files to delete. The release plan selects the exact consumed fragments.
