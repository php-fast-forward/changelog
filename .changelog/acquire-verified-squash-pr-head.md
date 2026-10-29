---
category: fixed
type: patch
---

Recovers missing original pull-request heads after squash merges by fetching only API-verified exact commits from the matching GitHub repository through authenticated HTTP, keeping credentials out of Git processes and persisted configuration, before rechecking implementation provenance. Failed acquisition or mismatched evidence still refuses version preparation without writing managed files.
