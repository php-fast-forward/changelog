---
category: changed
type: major
author: "mentordosnerds"
---

Adds a standalone fragment-based changelog and release lifecycle for Composer projects. The add/check/status/version/notes/publish/backfill/format commands replace the legacy Unreleased editing workflow, share their PHP domain with GitHub Actions, and support deterministic release plans, localized history, verified version pull requests and recoverable publication. This repository now records its own changes with the same CLI and consolidates CHANGELOG.md through its release workflow.
