---
category: changed
type: patch
---

Distinguishes repository-owned workflows with consistent names and one internal
changelog caller, preserving the public reusable and Action paths. Source archives
retain the six public Docker Actions and their build context for direct GitHub
consumption, while excluding internal automation and maintenance files.
Image publication also requires the approved merged-PR event and its exact
merge SHA at the internal caller, in addition to the verified publication outputs.
