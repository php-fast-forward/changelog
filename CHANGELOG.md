# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-17

### Added

- Bootstrap the standalone changelog domain, document model, manager, and reusable CLI commands (#1)
- Add a Git-baseline check command, explicit service contracts, a PSR-11 service provider, and lazy command loading (#1)
- Add deterministic PSR-20 time through `fast-forward/clock` and an isolated unit-test coverage gate (#1)
- Adds consistent short options for common settings, fragment metadata, read-only modes and note output while preserving Symfony global shortcuts and the existing long options.
- Ships the canonical changelog skill in Composer archives and declares its donor source for optional llm/skills installation, with a verified consumer recipe and an evaluated Fast Forward resource-bundle alternative.
- Allow every reusable changelog workflow to inherit JSON runner labels, supporting private repositories on existing self-hosted billing fallback runners while retaining the GitHub-hosted default.
- Supports optional GitHub App credentials in reusable workflows so generated
  pull requests can trigger application CI without approving each update.
  Trusted jobs create separate repository-scoped installation tokens while
  ordinary PR-head checks retain their read-only token.
- Adopt one reusable changelog lifecycle with exact-head validation, automatic validation of generated branches and configurable sticky PR feedback for successful, failed and recovered checks. Publication remains opt-in during consumer validation.
- Builds the self-contained CLI under /usr/local with a Composer root version build argument and publishes validated multi-platform release images to GHCR for existing release tags.

### Changed

- Distribute the repository skill from one real GitHub package, with relative links for other hosts and explicit validation of Windows checkout behavior.
- Documents the versioned consumer skill with GitHub adoption, executable PHP examples and explicit installation lifecycle choices.
- Release pull requests now update readable Markdown history and remove consumed fragments without adding a tracked release plan. Recovery journals stay outside the versioned tree and are removed after successful consolidation.
- Adds contextual Dash artwork organizing change fragments into a release history.
- Update dependencies: `shivammathur/setup-php`. ([#28](https://github.com/php-fast-forward/changelog/pull/28), @dependabot[bot])
- Preserve the Dependabot PR title in generated dependency notes, retaining the versions and update location supplied by Dependabot, and list the verified packages for grouped updates. Keep existing notes intact and avoid deriving release impact or security authority from editable PR text.
- Runs all GitHub Actions from the self-contained Docker CLI under .github/actions, with direct commands, validated outputs and separate consumer data.
- Makes GitHub automation the README's first adoption path, with a copyable caller
  pinned to a reviewed implementation. Documents both project and global CLI use,
  then directs agent skills and advanced integrations to their focused guides.
- Uses Symfony Console Ask for the required add description, retries blank interactive answers without trimming Markdown spaces, and preserves unattended invalid-input handling. Direct AddCommand invocations no longer receive InputInterface.
- Uses Symfony NotBlank constraints for interactive descriptions with configurable errors and preserved Markdown spaces, removing the field-specific ChangeDescriptionValidator class. AddCommand and CheckCommand take SymfonyStyle first, separated from their attributed input parameters.
- Aligns README sections, examples and verified badges with the Fast Forward package documentation format.
- Registers ChangelogServiceProvider by class name with lazy defaults and optional Fast Forward Config injection instead of provider constructor arguments.
- Use the reusable workflow's Ubuntu default in public onboarding examples and document every optional lifecycle input without requiring repository-specific runner variables.
- Distribute the uniquely named fast-forward-changelog skill from resources/skills, reserve agent directories for consumer-owned skills, exclude GitHub automation from Composer archives and retire unused host adapters and their Node synchronizer while retaining the four PHP development gates.
- Improves PHP, Dockerfile and workflow readability with separated statement blocks, grouped command attributes and wrapped arguments; keeps the formatting rules in the project quality gate.
- Moves semantic impact to `Version`, GitHub Actions outputs to `Automation\Output`, transport factories to `Http` and `Process`, and release-note rendering to `Release\Renderer`, with matching tests and updated PHP imports. Documents the SDK and development-tooling boundaries and excludes development scripts and formatter configuration from package archives.
- Adds a standalone fragment-based changelog and release lifecycle for Composer projects. The add/check/status/version/notes/publish/backfill/format commands replace the legacy Unreleased editing workflow, share their PHP domain with GitHub Actions, and support deterministic release plans, localized history, verified version pull requests and recoverable publication. This repository now records its own changes with the same CLI and consolidates CHANGELOG.md through its release workflow.
- Distinguish public reusable workflows from repository automation, provide a single consumer caller example, standardize Action names and YAML formatting, use verified external Action version tags and the standard coverage comment, and validate both highest and lowest runtime dependency bounds. Keep PHP diagnostics on stderr so older compatible dependencies cannot corrupt CLI JSON output.

### Fixed

- Fix release ordering and version-prefix handling in changelog promotion, inference, and links (#1)
- Distinguish an absent baseline changelog from Git failures instead of accepting an unknown baseline (#1)
- Preserve CRLF documents, multiline list entries, nested bullets, and existing reference definitions during mutations (#1)
- Strip credentials from generated repository links and render release-note output without Symfony formatting (#1)
- Validate real release dates and create missing parent directories before Git repository discovery (#1)
- Treat prefixed versions as one identity and apply SemVer prerelease precedence when ordering releases (#1)
- Recognize existing Changesets version-only release headings when adopting the CLI, preserving their exact historical Markdown while using the selected template for new releases. Keep empty paragraph separators free of generated indentation so multiline release notes pass whitespace checks without a post-processing script.
- Keeps an owned version PR unchanged across calendar days when fresh inputs reconstruct the same signed transaction, preserving its release date, exact history and fragment scope.
- Accept the verified `github_actions` ecosystem from Dependabot metadata and normalize it to `github-actions`, allowing GitHub Actions dependency PRs to receive fragments while preserving contribution identity, scope and package validation.

  Validate the resulting Dependabot head and update its changelog status and sticky comment in the same trusted workflow run, including when the default GitHub token does not trigger another PR event.
- Build the repository's privileged Dependabot automation from the immutable commit of its trusted workflow, rather than the PR event's potentially older base. Resolve the live comparison base separately before validating and commenting on the resulting contribution.
- Preloads trusted Dockerfile base images through the public Docker Hub cache on disposable GitHub-hosted Linux runners, reducing build failures from upstream registry limits and timeouts.
- Reject invalid UTF-8 and unclosed structural code fences before a fragment can pass contribution checks, and retain the verified container 1.6 resolution contract so fresh installations cannot silently bypass a failed explicit command factory.
- Allow verified maintainer maintenance to remove an incorrect optional author from a pending fragment only when committed regular-file modes and every other byte remain unchanged. Ordinary contributions and waivers cannot change inherited fragments.
- Accept GitHub platform-signed bot transactions while preserving exact author identity, signature, receipt and file-scope checks; expose safe commit diagnostics when ownership proof fails.
- Preserve managed-version authorization in the ordinary CLI check and reject ambiguous category headings so approved fragment consumption validates consistently and custom presentation cannot silently reclassify historical notes.
- Separate release and category headings with blank lines while keeping simple changelog entries compact and preserving multiline Markdown.
- Promotes legacy Unreleased descriptions into the next version without leaving a pending section or duplicating category headings.
- Start multiline release descriptions beside their list marker instead of displaying an empty bullet before the text, while preserving authored line breaks, paragraph spacing and nested Markdown.
- Validate generated release PRs and update sticky changelog comments on self-hosted runners without requiring GitHub CLI or jq on the host.
- Reject ambiguous Unreleased headings and unsupported historical release headings before consolidation, so custom template changes cannot duplicate history or misidentify an existing release.
- Confirm refreshed version pull requests through a fresh read of the same pull request, so a stale GitHub update response cannot report a successful changelog transaction as failed.
