# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-17

### Added

- Bootstrap the standalone changelog domain, document model, manager, and reusable CLI commands (#1)
- Add a Git-baseline check command, explicit service contracts, a PSR-11 service provider, and lazy command loading (#1)
- Add deterministic PSR-20 time through `fast-forward/clock` and an isolated unit-test coverage gate (#1)
- Resolves missing pull-request links from verified committed fragment origins during release preparation and revalidates them before publication, without editing contributor fragments or adding commit links. Local discovery is opt-in; GitHub lifecycle jobs share the enabled policy by default. Direct PHP construction of release planning and publication validation requires the new reference resolver collaborator. ([#40](https://github.com/php-fast-forward/changelog/pull/40))
- Adds consistent short options for common settings, fragment metadata, read-only modes and note output while preserving Symfony global shortcuts and the existing long options. ([#27](https://github.com/php-fast-forward/changelog/pull/27))
- Ships the canonical changelog skill in Composer archives and declares its donor source for optional llm/skills installation, with a verified consumer recipe and an evaluated Fast Forward resource-bundle alternative. ([#27](https://github.com/php-fast-forward/changelog/pull/27))
- Allow every reusable changelog workflow to inherit JSON runner labels, supporting private repositories on existing self-hosted billing fallback runners while retaining the GitHub-hosted default. ([#24](https://github.com/php-fast-forward/changelog/pull/24))
- Supports optional GitHub App credentials in reusable workflows so generated
  pull requests can trigger application CI without approving each update.
  Trusted jobs create separate repository-scoped installation tokens while
  ordinary PR-head checks retain their read-only token.

  ([#34](https://github.com/php-fast-forward/changelog/pull/34))
- Adds missing Keep a Changelog version links during release preparation, preserving existing references and comparing consecutive release tags. ([#39](https://github.com/php-fast-forward/changelog/pull/39))
- Allows verified human editorial maintenance to replace pending fragments with new committed, validated summaries while preserving exact Git blob and file-mode checks, ordinary contribution protections and independent release-publication evidence. ([#41](https://github.com/php-fast-forward/changelog/pull/41))
- Adopt one reusable changelog lifecycle with exact-head validation, automatic validation of generated branches and configurable sticky PR feedback for successful, failed and recovered checks. Publication remains opt-in during consumer validation. ([#23](https://github.com/php-fast-forward/changelog/pull/23))
- Supports configurable version PR titles containing the current and next planned versions. A title-only update preserves the signed transaction and branch head, while the PR description clarifies that publication after merge depends on the consumer workflow. ([#35](https://github.com/php-fast-forward/changelog/pull/35))
- Builds the self-contained CLI under /usr/local with a Composer root version build argument and publishes validated multi-platform release images to GHCR for existing release tags. ([#18](https://github.com/php-fast-forward/changelog/pull/18))

### Changed

- Distribute the repository skill from one real GitHub package, with relative links for other hosts and explicit validation of Windows checkout behavior. ([#8](https://github.com/php-fast-forward/changelog/pull/8))
- Documents the versioned consumer skill with GitHub adoption, executable PHP examples and explicit installation lifecycle choices. ([#32](https://github.com/php-fast-forward/changelog/pull/32))
- Release pull requests now update readable Markdown history and remove consumed fragments without adding a tracked release plan. Recovery journals stay outside the versioned tree and are removed after successful consolidation. ([#8](https://github.com/php-fast-forward/changelog/pull/8))
- Broadens verified Symfony component compatibility and refreshes the locked development tools. Console still requires Symfony 8.1; the compatible Container range preserves explicit factory failures. ([#39](https://github.com/php-fast-forward/changelog/pull/39))
- Adds contextual Dash artwork organizing change fragments into a release history. ([#12](https://github.com/php-fast-forward/changelog/pull/12))
- Update dependencies: `shivammathur/setup-php`. ([#28](https://github.com/php-fast-forward/changelog/pull/28), @dependabot[bot])
- Preserve the Dependabot PR title in generated dependency notes, retaining the versions and update location supplied by Dependabot, and list the verified packages for grouped updates. Keep existing notes intact and avoid deriving release impact or security authority from editable PR text. ([#31](https://github.com/php-fast-forward/changelog/pull/31))
- Runs all GitHub Actions from the self-contained Docker CLI under .github/actions, with direct commands, validated outputs and separate consumer data. ([#17](https://github.com/php-fast-forward/changelog/pull/17))
- Makes GitHub automation the README's first adoption path, with a copyable caller
  pinned to a reviewed implementation. Documents both project and global CLI use,
  then directs agent skills and advanced integrations to their focused guides.

  ([#29](https://github.com/php-fast-forward/changelog/pull/29))
- Uses Symfony Console Ask for the required add description, retries blank interactive answers without trimming Markdown spaces, and preserves unattended invalid-input handling. Direct AddCommand invocations no longer receive InputInterface. ([#20](https://github.com/php-fast-forward/changelog/pull/20))
- Uses Symfony NotBlank constraints for interactive descriptions with configurable errors and preserved Markdown spaces, removing the field-specific ChangeDescriptionValidator class. AddCommand and CheckCommand take SymfonyStyle first, separated from their attributed input parameters. ([#27](https://github.com/php-fast-forward/changelog/pull/27))
- Distinguishes repository-owned workflows with consistent names and one internal
  changelog caller, preserving the public reusable and Action paths. Source archives
  retain the six public Docker Actions and their build context for direct GitHub
  consumption, while excluding internal automation and maintenance files.
  Image publication also requires the approved merged-PR event and its exact
  merge SHA at the internal caller, in addition to the verified publication outputs.

  ([#38](https://github.com/php-fast-forward/changelog/pull/38))
- Aligns README sections, examples and verified badges with the Fast Forward package documentation format. ([#15](https://github.com/php-fast-forward/changelog/pull/15))
- Registers ChangelogServiceProvider by class name with lazy defaults and optional Fast Forward Config injection instead of provider constructor arguments. ([#18](https://github.com/php-fast-forward/changelog/pull/18))
- Use the reusable workflow's Ubuntu default in public onboarding examples and document every optional lifecycle input without requiring repository-specific runner variables. ([#33](https://github.com/php-fast-forward/changelog/pull/33))
- Distribute the uniquely named fast-forward-changelog skill from resources/skills, reserve agent directories for consumer-owned skills, exclude GitHub automation from Composer archives and retire unused host adapters and their Node synchronizer while retaining the four PHP development gates. ([#25](https://github.com/php-fast-forward/changelog/pull/25))
- Improves PHP, Dockerfile and workflow readability with separated statement blocks, grouped command attributes and wrapped arguments; keeps the formatting rules in the project quality gate. ([#19](https://github.com/php-fast-forward/changelog/pull/19))
- Moves semantic impact to `Version`, GitHub Actions outputs to `Automation\Output`, transport factories to `Http` and `Process`, and release-note rendering to `Release\Renderer`, with matching tests and updated PHP imports. Documents the SDK and development-tooling boundaries and excludes development scripts and formatter configuration from package archives. ([#27](https://github.com/php-fast-forward/changelog/pull/27))
- Removes redundant value, exception and construction factory classes and interfaces. Service composition uses interface aliases and autowiring; consumers using the removed pre-release factory contracts or constructor arguments must migrate to direct value creation and the retained domain factories. ([#39](https://github.com/php-fast-forward/changelog/pull/39))
- Improves source readability with named release phases and descriptive PHPDoc, and builds browsable guides and API documentation with phpDocumentor. ([#39](https://github.com/php-fast-forward/changelog/pull/39))
- Adds a standalone fragment-based changelog and release lifecycle for Composer projects. The add/check/status/version/notes/publish/backfill/format commands replace the legacy Unreleased editing workflow, share their PHP domain with GitHub Actions, and support deterministic release plans, localized history, verified version pull requests and recoverable publication. This repository now records its own changes with the same CLI and consolidates CHANGELOG.md through its release workflow. ([#27](https://github.com/php-fast-forward/changelog/pull/27))
- Distinguish public reusable workflows from repository automation, provide a single consumer caller example, standardize Action names and YAML formatting, use verified external Action version tags and the standard coverage comment, and validate both highest and lowest runtime dependency bounds. Keep PHP diagnostics on stderr so older compatible dependencies cannot corrupt CLI JSON output. ([#27](https://github.com/php-fast-forward/changelog/pull/27))

### Fixed

- Fix release ordering and version-prefix handling in changelog promotion, inference, and links (#1)
- Distinguish an absent baseline changelog from Git failures instead of accepting an unknown baseline (#1)
- Preserve CRLF documents, multiline list entries, nested bullets, and existing reference definitions during mutations (#1)
- Strip credentials from generated repository links and render release-note output without Symfony formatting (#1)
- Validate real release dates and create missing parent directories before Git repository discovery (#1)
- Treat prefixed versions as one identity and apply SemVer prerelease precedence when ordering releases (#1)
- Recognize existing Changesets version-only release headings when adopting the CLI, preserving their exact historical Markdown while using the selected template for new releases. Keep empty paragraph separators free of generated indentation so multiline release notes pass whitespace checks without a post-processing script. ([#26](https://github.com/php-fast-forward/changelog/pull/26))
- Keeps an owned version PR unchanged across calendar days when fresh inputs reconstruct the same signed transaction, preserving its release date, exact history and fragment scope. ([#33](https://github.com/php-fast-forward/changelog/pull/33))
- Resolves the current PR base before validating feedback after an automated
  Dependabot fragment commit, so a concurrently advanced base does not reuse the
  event's stale comparison. Ordinary event checks retain their exact supplied base.

  ([#38](https://github.com/php-fast-forward/changelog/pull/38))
- Accept the verified `github_actions` ecosystem from Dependabot metadata and normalize it to `github-actions`, allowing GitHub Actions dependency PRs to receive fragments while preserving contribution identity, scope and package validation.

  Validate the resulting Dependabot head and update its changelog status and sticky comment in the same trusted workflow run, including when the default GitHub token does not trigger another PR event.

  ([#29](https://github.com/php-fast-forward/changelog/pull/29))
- Build the repository's privileged Dependabot automation from the immutable commit of its trusted workflow, rather than the PR event's potentially older base. Resolve the live comparison base separately before validating and commenting on the resulting contribution. ([#30](https://github.com/php-fast-forward/changelog/pull/30))
- Preloads trusted Dockerfile base images through the public Docker Hub cache on disposable GitHub-hosted Linux runners, reducing build failures from upstream registry limits and timeouts. ([#19](https://github.com/php-fast-forward/changelog/pull/19))
- Reject invalid UTF-8 and unclosed structural code fences before a fragment can pass contribution checks, and retain the verified container 1.6 resolution contract so fresh installations cannot silently bypass a failed explicit command factory. ([#23](https://github.com/php-fast-forward/changelog/pull/23))
- Allow verified maintainer maintenance to remove an incorrect optional author from a pending fragment only when committed regular-file modes and every other byte remain unchanged. Ordinary contributions and waivers cannot change inherited fragments. ([#27](https://github.com/php-fast-forward/changelog/pull/27))
- Accept GitHub platform-signed bot transactions while preserving exact author identity, signature, receipt and file-scope checks; expose safe commit diagnostics when ownership proof fails. ([#27](https://github.com/php-fast-forward/changelog/pull/27))
- Preserve managed-version authorization in the ordinary CLI check and reject ambiguous category headings so approved fragment consumption validates consistently and custom presentation cannot silently reclassify historical notes. ([#23](https://github.com/php-fast-forward/changelog/pull/23))
- Separate release and category headings with blank lines while keeping simple changelog entries compact and preserving multiline Markdown. ([#13](https://github.com/php-fast-forward/changelog/pull/13))
- Promotes legacy Unreleased descriptions into the next version without leaving a pending section or duplicating category headings. ([#15](https://github.com/php-fast-forward/changelog/pull/15))
- Publishes managed version PRs after ordinary squash merges using the approved signed head's evidence, including after branch deletion. The tag remains on the approved merge, with complete tree, release content and option validation before publication. ([#36](https://github.com/php-fast-forward/changelog/pull/36))
- Start multiline release descriptions beside their list marker instead of displaying an empty bullet before the text, while preserving authored line breaks, paragraph spacing and nested Markdown. ([#33](https://github.com/php-fast-forward/changelog/pull/33))
- Validate generated release PRs and update sticky changelog comments on self-hosted runners without requiring GitHub CLI or jq on the host. ([#27](https://github.com/php-fast-forward/changelog/pull/27))
- Reject ambiguous Unreleased headings and unsupported historical release headings before consolidation, so custom template changes cannot duplicate history or misidentify an existing release. ([#10](https://github.com/php-fast-forward/changelog/pull/10))
- Verifies the approved merge through GraphQL when GitHub REST 2026 omits its merge commit field, preserving exact PR, repository, branch, head and Bot checks before publishing the unchanged release transaction, including repository IDs above 32 bits. Routes Enterprise Server GraphQL queries to their same-origin endpoint while retaining REST paths. ([#37](https://github.com/php-fast-forward/changelog/pull/37))
- Confirm refreshed version pull requests through a fresh read of the same pull request, so a stale GitHub update response cannot report a successful changelog transaction as failed. ([#11](https://github.com/php-fast-forward/changelog/pull/11))
- Keeps discovered fragment paths consistent on Windows so release planning and application use the same canonical inventory and strict receipt format. ([#36](https://github.com/php-fast-forward/changelog/pull/36))

[1.0.0]: https://github.com/php-fast-forward/changelog/releases/tag/v1.0.0
