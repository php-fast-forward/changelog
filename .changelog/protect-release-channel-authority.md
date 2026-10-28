---
category: changed
type: patch
---

Replaces the repository-only PHP release-channel updater and manual SHA dispatch
with actions-tagger on published stable release events, using moving major tags.
Activation requires separately approved publication credentials, environment and
channel rules; the action does not retain atomic old-SHA or ancestry checks.
