# Workflow contract fixtures

Run `composer test:workflows` on Linux or macOS with Bash and Node.js. Symfony YAML is
a development dependency; the distributed CLI and Docker image do not need it.

The fixture reads the actual reusable workflows, checks their nested inputs and
permission grants, and executes the actual Bash and GitHub Script guards. It
injects a synthetic GitHub API client and output collector into the actual inline
JavaScript, including API failures and stale PR snapshots. Bash cache guards use
disposable output files and reject any self-hosted daemon operation. No GitHub
request, real credential, consumer installation, tag or release is used.

Cases cover trusted base events, default branch overrides, explicit and resolved
base SHAs, invalid identities/modes, inert shell metacharacters, operational
feedback, failed gates, and comments discarded after a PR moves or closes.
Repository contracts preserve required check names, route PRs through the read-only
module and trusted events through the facade, and bind image inputs only to
publication outputs. Lock checks prevent a caller from holding the same group
as its nested release operation; the public modules own trusted-operation locks.
PHP unit tests separately cover validation, exact committed blobs, status writes,
failure output, policy and bounded Markdown previews.

These checks do not execute GitHub's scheduler, Docker runner, token-permission
model, sticky comment action or required-status settings in a consumer. A pilot
repository must verify those effective platform behaviors before release.
