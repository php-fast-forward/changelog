---
category: fixed
type: patch
---

Render fragment descriptions that already contain a Markdown list without adding a second outer bullet. Use CommonMark source classification to preserve list numbering, nesting, raw HTML, literal code whitespace and historical release boundaries; keep delivery references outside multiline code and comments, and reject unclosed fragment blocks before consolidation.
