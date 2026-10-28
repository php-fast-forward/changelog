---
category: security
type: patch
---

Separates repository release-channel updates into a dedicated GitHub App, removes
shared-token fallbacks, and requires immutable publication and exact managed PR
merge evidence for recovery. Adds a reviewable protection configuration; remote
App, environment and ruleset activation require separate approval.
