---
category: fixed
type: patch
---

Accept the verified `github_actions` ecosystem from Dependabot metadata and normalize it to `github-actions`, allowing GitHub Actions dependency PRs to receive fragments while preserving contribution identity, scope and package validation.

Validate the resulting Dependabot head and update its changelog status and sticky comment in the same trusted workflow run, including when the default GitHub token does not trigger another PR event.
