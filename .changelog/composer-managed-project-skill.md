---
category: changed
type: minor
---

Project `install` now offers the `llm/skills` Composer manager with the Changelog GitHub source, so projects can restore the agent instructions through an explicitly approved Composer plugin without requiring the Changelog runtime. Setup previews the changes, preserves manager configuration and foreign skill ownership, and uses a verified standalone manager without activating consumer plugins or autoloaders. The legacy native `skill:install` command remains available.
