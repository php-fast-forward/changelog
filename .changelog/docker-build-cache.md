---
category: fixed
type: patch
---

Preloads trusted Dockerfile base images through the public Docker Hub cache on disposable GitHub-hosted Linux runners, reducing build failures from upstream registry limits and timeouts.
