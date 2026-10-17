# Fast Forward Changelog

Automate changelog checks, version pull requests and approved publication on
GitHub. Contributions record independent Markdown fragments in `.changelog/`;
the version workflow consolidates them into `CHANGELOG.md`.

## GitHub Automation

Start with the [reusable lifecycle](reusable-lifecycle.md) and
[adoption guide](adoption.md). One consumer workflow connects contribution
checks, PR feedback, Dependabot notes, version preparation and publication.

- [Workflow and Action map](automation-map.md)
- [Individual GitHub Actions and reusable workflows](github-actions.md)
- [GitHub App credentials and permissions](github-app.md)
- [Version pull requests and title customization](version-pull-request.md)
- [Approved-commit publication](publication.md)
- [Docker CLI image](docker.md)

## CLI And Integration

Use the complete CLI as a development dependency or global Composer package.
The [fragment guide](cli-fragments.md) covers authoring and checking contributions;
the [history guide](history.md) explains formatting and backfilling history.

- [Release transactions and recovery](release-receipts.md)
- [Dependency and container contracts](dependencies.md)
- [Source namespaces and extension boundaries](source-layout.md)
- [Git and GitHub integration choices](integration-dependencies.md)
- [Consumer agent skill](skills.md)
- [Optional Composer skill installation](composer-skills.md)

## Contributors

- [Project foundation and quality gates](foundation.md)
- [This repository's changelog automation](self-changelog.md)
- [Documentation build and source map](documentation.md)
- [Instruction map](instruction-map.md)
- [Validation and publication policies](policies.md)
- [Fragment format](specification/fragment-format.md)
- [Versioning rules](specification/versioning.md)

The API reference in the generated site's namespace sidebar comes from `src/`
PHPDoc. These guides explain complete
workflows, prerequisites and operational boundaries alongside that reference.
