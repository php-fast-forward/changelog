---
category: fixed
type: patch
---

Build the repository's privileged Dependabot automation from the immutable commit of its trusted workflow, rather than the PR event's potentially older base. Resolve the live comparison base separately before validating and commenting on the resulting contribution.
