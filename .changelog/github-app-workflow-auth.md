---
category: added
type: minor
---

Supports optional GitHub App credentials in reusable workflows so generated
pull requests can trigger application CI without approving each update.
Trusted jobs create separate repository-scoped installation tokens while
ordinary PR-head checks retain their read-only token.
