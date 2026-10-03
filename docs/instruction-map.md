# Instruction scope map

The current and foundation target both contain one repository contract:
`AGENTS.md`. It owns development commands, independence from DevTools, testing
isolation, quality, preservation of local work and publication authority.
The host bootstrap is supplemental and provides RTK and canonical skill routing.

| Decision | Contract | Scope | Reason |
| --- | --- | --- | --- |
| Update | `AGENTS.md` | Repository | Make runtime, independent checks and isolation explicit. |
| Retain | `SKILL.md` in a future distributable skill | Consumer procedure | Package procedures stay in their own entrypoint. |
| Defer | Child instruction contracts | Independent future boundaries | Create only if an automation or packaging subtree needs durable rules. |

Read the repository contract before editing any source, test, development
configuration or documentation. There are no child scopes or Child DOX Index
entries to refresh in this foundation. Plain folders share the root rules.

Restore `AGENTS.md` and this map together to roll back the instruction update.
Verification consists of reading the root, checking its relative links and
confirming that no duplicate `AGENTS.md` files shadow package entrypoints.
