---
category: security
type: patch
---

Excludes vulnerable Symfony Filesystem, HTTP Client, Validator and YAML releases, requiring 7.4.23 or 8.1.11 and updating their locked patches. Retains compatibility testing for unaffected 8.0 components with the patched 7.4 implementations and adds the secure 8.1 component family without suppressing advisories. Updates the vulnerable PHPUnit development tool to 13.4.4 with its required Git State 1.1 dependency.
