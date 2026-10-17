---
category: fixed
type: patch
---

Verifies the approved merge through GraphQL when GitHub REST 2026 omits its merge commit field, preserving exact PR, repository, branch, head and Bot checks before publishing the unchanged release transaction. Routes Enterprise Server GraphQL queries to their same-origin endpoint while retaining REST paths.
