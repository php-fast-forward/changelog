---
category: fixed
type: patch
---

Render fragment descriptions that already contain a Markdown list without adding a second outer bullet. Use CommonMark source classification to preserve list numbering, nesting, raw HTML, literal code whitespace and historical release boundaries; keep delivery references within their owning items and outside literal blocks, and reject HTML tails that would hide subsequent headings or references.
