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
The image caller's actual Boolean `if` condition is evaluated with JSON-resolved
context references in an isolated Node process. Cases reject forged publication
outputs on ineligible events and mismatched merge SHAs; they do not substitute
for GitHub's expression engine or the native CodeQL result.
PHP unit tests separately cover validation, exact committed blobs, status writes,
failure output, policy and bounded Markdown previews.

Repository validation uses the complete immutable PR diff, including deleted paths
and file modes. Changelog-only changes, including a prepared version PR, keep the
existing required check names with explicit successful no-op steps. Source,
automation, shipped resources, tooling, unknown paths or an unavailable comparison
run the full package gates. Guide changes build documentation; source and tool
changes build the API as well. Main pushes and manual runs always validate fully.
The contribution workflow still validates exact consolidation and fragment ownership.

The Node impact fixture exercises the real classifier and a disposable Git history:
release deletions, guide assets, source mixed with history, renames, symlinks,
executable modes, unknown inputs and incomplete comparisons. Matrix contexts fail
if their upstream classification failed or omitted its decision. Trigger-level path
filters never hide a required context. Coverage uploads run only when coverage did.
The full harness also supplies the actual workflow loader to an adversarial fixture:
a forged PR helper never executes, first adoption runs all gates, unsupported
protected modes use full validation, and malformed/missing commit identities fail
without producing outputs. This protected-base loader scenario requires
`composer test:workflows`; the direct Node command tests classifier behavior alone.

Ubuntu owns the common quality gates and the unchanged 100% source-line coverage
threshold. ARM and Windows retain unit and real integration tests without repeating
coverage, Rector and ECS. Integration uses PHPUnit's live debug output and an
incremental event log, so failures and the last started test remain visible even
when the run cannot finalize JUnit. Windows uploads both files when its artifact
step can run. A disposable failing-then-interrupted PHPUnit fixture verifies these
diagnostics and the nonzero exit status using the workflow's actual flags.

The squash corpus tests every Markdown variant through real release planning and
exact history assertions. Publication transport additionally exercises missing and
stale trailers, a committed custom template, hostile inherited Git configuration,
literal Markdown/references and independent fragment boundaries. This keeps native
Git publication coverage on every platform without cloning and reconstructing an
identical publication transaction for each Markdown variant.

Windows runs ordinary references, stacked references, Git provenance and the
remaining integration tests in four parallel jobs. New ungrouped tests belong to
the remaining-tests partition.
The workflow harness compares real PHPUnit test IDs, including every provider
case, to prove the partitions are disjoint and cover the complete suite. Each
partition retains its own diagnostics. The existing required
`PHP 8.5 (windows-latest)` context waits for the entire platform matrix and rejects
failed, cancelled, skipped or missing results; it cannot pass while a shard runs.
This reduces elapsed time, with additional runner setup and repeated unit-test
cost. Linux and ARM still run the complete integration suite.

The automatic-reference fixture clears inherited Git overrides and synchronizes
its isolated configuration across the process environment and PHP superglobals.
Symfony Process derives child environments from all three sources; `putenv()`
alone did not isolate system Git configuration on Windows. The stacked scenario
injects a hostile line-ending override first and asserts exact worktree bytes
after checkout, preserving the original provenance rejection assertion.

These checks do not execute GitHub's scheduler, Docker runner, token-permission
model, sticky comment action or required-status settings in a consumer. A pilot
repository must verify those effective platform behaviors before release.
