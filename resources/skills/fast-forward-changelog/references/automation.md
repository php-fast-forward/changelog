# GitHub lifecycle adoption

Load when installing or changing workflows, configuring credentials or runners,
selecting maintenance lines, or diagnosing feedback. Discover the latest stable
published release for new adoption, respecting an explicitly selected release
line. For ordinary work, retain the application's reviewed implementation.

## Use the selected release's caller

From the verified full tag, read `README.md`,
`resources/workflows/changelog.yml`, its companion README, and the reusable
workflow referenced by that caller. Consult that tag's relevant guides, including
`docs/reusable-lifecycle.md`, `docs/github-app.md` and `docs/automation-map.md` when
present. If paths moved, follow that release's README rather than guessing.
Do not combine a caller from one version with settings copied from `main`.

If that release lists `install`, inspect its command help and installation guide
before choosing a route. Guided setup can ask for the release branch, publication
choice, advanced overrides and optional project skill, then preview a missing
caller. It must preserve an existing caller and refuse ambiguous or competing
automation. Use read-only preview first; noninteractive application requires its
documented confirmation option. The workflow and bundled skill may have separate
provenance: a workflow ref does not change the executing CLI's instructions.

For manual setup, copy the caller into the application's `.github/workflows/`, review its events,
inputs and effective permissions, then set its product references to the selected
published complete tag or verified immutable release commit. Review older pins
embedded in the copied caller. After replacing a pin, inspect the reusable
workflow at the final selected ref and verify its actual inputs, permissions and
runner requirements before saving the application caller.
GitHub `uses` references the executable reusable
workflow under `.github/workflows`; the resource caller is a copyable asset.
Internal `repository-*` workflows belong to the library, not the consumer.

For normal automatic publication, explain that merging the reviewed version PR
creates its approved tag and GitHub Release; setup does not merge or publish it.
Choose disabled publication only for an explicitly requested validation-only
pilot, after reviewing that release's recovery contract. Choose base and managed
branches, fragment/history paths, historical sources and PR-reference policy consistently
across enabled operations. For parallel maintenance, select a separate managed
branch per release line and verify its actual planned version; a branch's name
does not enforce a SemVer range. Use individual workflows or Docker actions only
when their release-specific contracts suit the application's orchestration.

Full tags preserve a concrete implementation. A moving major channel is an
explicit update policy and must be documented by the selected product release;
do not assume it exists. In particular `@v0` may cross breaking `0.x` minors and
does not mean Composer `^0.1`. Changing a workflow ref is a deliberate update.

## Credentials and acceptance

Use the selected workflow's documented token/App route and the application's
approved secret storage. Preserve the verified automation identity and narrow
permissions. Never check out untrusted PR code in a privileged custom job.
Token-generated events may behave differently from human/App events; consult
the selected release and GitHub's current rules before promising downstream CI.

When a validation-only pilot is requested, keep publication disabled. Verify a
valid fragment, missing or malformed notes, the exact PR head's required status and sticky-comment recovery,
any enabled bot operation, and the generated version PR. Check the actual runner
requirements and access when changing Linux/self-hosted selection. Test custom
policy changes relevant to the application rather than assuming a default-branch
green run proves its generated head.

The version PR must preserve exact notes and consume only its accepted fragment
set, with no technical plan files or new `Unreleased` section. Head changes need
fresh review and checks. Successful preparation does not authorize merging or
publication. Enable publication only under existing authority for the verified
result, using the selected release's committed evidence and recovery procedure.
