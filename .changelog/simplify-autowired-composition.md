---
category: changed
type: major
pull_request: 39
---

Removes redundant value, exception and construction factory classes and interfaces. Service composition uses interface aliases and autowiring; consumers using the removed pre-release factory contracts or constructor arguments must migrate to direct value creation and the retained domain factories.
