---
category: fixed
type: patch
---

Preserve automatic fragment pull-request references when original PR history
contains large artifacts. Recover the complete commit and tree graph through
bounded native Git filtering and verify it in a temporary object scope, retaining
the existing fragment hashes, direct-origin checks and repository evidence.
Keep the consumer's Git objects, refs and configuration unchanged and preserve
the complete-pack APIs for existing integrations.
