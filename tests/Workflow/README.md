# Workflow contract fixtures

Run `composer test:workflows` on Linux or macOS with Bash and jq. Symfony YAML is
a development dependency; the distributed CLI and Docker image do not need it.

The fixture reads the actual reusable workflows, checks their nested inputs and
permission grants, and executes the actual Bash context/freshness guards. It
replaces `gh` with a function returning synthetic metadata and uses disposable
output files. No GitHub request, real credential, consumer installation, tag or
release is used.

Cases cover trusted base events, default branch overrides, explicit and resolved
base SHAs, invalid identities/modes, inert shell metacharacters, operational
feedback, failed gates, and comments discarded after a PR moves or closes.
PHP unit tests separately cover validation, exact committed blobs, status writes,
failure output, policy and bounded Markdown previews.

These checks do not execute GitHub's scheduler, Docker runner, token-permission
model, sticky comment action or required-status settings in a consumer. A pilot
repository must verify those effective platform behaviors before release.
