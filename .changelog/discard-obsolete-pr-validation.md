---
category: fixed
type: patch
---

Discard obsolete PR feedback validations when the PR closes or its head, base or release line changes, including version PRs merged during validation. Preserve failures for invalid current contributions and unavailable evidence without publishing stale final statuses or comments.
