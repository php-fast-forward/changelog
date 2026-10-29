---
category: changed
type: patch
---

Replaces the repository-only PHP channel updater and its manual SHA dispatch with
actions-tagger@v2.0.3 for published/edited stable releases. A short inline selector
prevents stale releases from regressing major branches and gates the latest tag
across majors. Concrete version tags remain separate and immutable; App,
environment and ruleset activation still require separate approval.
