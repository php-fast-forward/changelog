---
category: fixed
type: patch
---

Resolves the current PR base before validating feedback after an automated
Dependabot fragment commit, so a concurrently advanced base does not reuse the
event's stale comparison. Ordinary event checks retain their exact supplied base.
