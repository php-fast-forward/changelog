# Fast Forward Changelog

Automate changelog checks, version PRs and approved publication on GitHub.
Contributors write independent `.changelog/*.md` fragments; the version
workflow consolidates them into `CHANGELOG.md`.

## Start here

| Task | Guide |
| --- | --- |
| Set up GitHub automation | [Adoption](adoption.md) → [Reusable lifecycle](reusable-lifecycle.md) |
| Run the local CLI through CPX | [Local first use](adoption.md) → [Fragment and command reference](cli-fragments.md) |
| Give an agent the consumer instructions | [Skill installation](skills.md) |
| Integrate the PHP services | [PHP API](adoption.md) → [Dependencies](dependencies.md) |

## Automation and releases

- [Reusable lifecycle](reusable-lifecycle.md), the complete
  [workflow and Action parameter reference](workflow-reference.md),
  [individual recipes](github-actions.md) and [workflow map](automation-map.md)
- [GitHub App credentials](github-app.md) and [validation policies](policies.md)
- [Version PRs and title customization](version-pull-request.md),
  [approved publication](publication.md) and [local transaction recovery](release-receipts.md)
- [History, formatting and templates](history.md), [Docker runtime](docker.md)
  and [fragment/versioning specifications](specification/index.md)

## Advanced integration and contribution

- [Source namespaces and API migrations](source-layout.md),
  [dependency contracts](dependencies.md) and [Git/GitHub adapter choices](integration-dependencies.md)
- [Optional Composer skill synchronization](composer-skills.md)
- [Contributor quality gates](foundation.md), [this repository's changelog flow](self-changelog.md)
  and [instruction map](instruction-map.md)
- [Documentation build and source map](documentation.md)

The generated site's namespace sidebar contains the PHP API reference from
`src/` PHPDoc. Use it for signatures alongside the task guides above.
