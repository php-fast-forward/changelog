import { relative, isAbsolute, sep } from 'node:path';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { isDeepStrictEqual as equal } from 'node:util';
import { parseJson } from './dependabot-patch.mjs';
const hash = (bytes) => createHash('sha256').update(bytes).digest('hex');
const requireProof = (condition, reason) => { if (!condition) throw Error(reason); };

/** Use the packaged trusted compiler over a clean immutable consumer data checkout.
 * runtimeCli is selected by the trusted caller, never from consumer vendor/bin.
 * The built-in template and local tags route prevent loading consumer PHP/templates.
 */
export function compileVersionPlan({ runtimeCli, project, base, repository }, run = execFileSync) {
  requireProof(isAbsolute(runtimeCli) && isAbsolute(project) && relative(project, runtimeCli).startsWith('..' + sep), 'Trusted runtime must be outside the consumer snapshot.');
  requireProof(/^[a-f0-9]{40}$/.test(base), 'Immutable base required.');
  const options = { cwd: project, encoding: 'utf8', timeout: 120000, maxBuffer: 8 * 1024 * 1024,
    env: { PATH: process.env.PATH, LANG: 'C.UTF-8', HOME: process.env.TMPDIR || '/tmp',
      TMPDIR: process.env.TMPDIR || '/tmp', TEMP: process.env.TEMP || '/tmp', TMP: process.env.TMP || '/tmp',
      GIT_CONFIG_NOSYSTEM: '1', GIT_CONFIG_GLOBAL: '/dev/null', GIT_OPTIONAL_LOCKS: '0' } };
  requireProof(run('git', ['rev-parse', 'HEAD'], options).trim() === base, 'Compiler checkout is not the exact base.');
  requireProof(run('git', ['status', '--porcelain', '--untracked-files=all'], options) === '', 'Compiler checkout is dirty.');
  const plan = parseJson(run('php', ['-d', 'xdebug.mode=off', runtimeCli, 'version', '--dry-run', '--no-interaction', `--cwd=${project}`,
    '--base-ref=HEAD', '--template=keep-a-changelog', `--repository=${repository}`,
    '--source=tags', '--resolve-pull-requests=false'], options));
  requireProof(run('git', ['rev-parse', 'HEAD'], options).trim() === base
    && run('git', ['status', '--porcelain', '--untracked-files=all'], options) === '', 'Compiler snapshot changed.');
  plan.consumed = Object.fromEntries(Object.entries(plan.consumed ?? {}).map(([path, digest]) => {
    const local = relative(project, path);
    requireProof(local !== '' && !isAbsolute(local) && local !== '..' && !local.startsWith('..' + sep), 'Fragment escapes compiler snapshot.');
    return [local.split(sep).join('/'), digest];
  }));
  return plan;
}

/** Compare the real compiler result with immutable version-PR blobs and authorized receipts.
 * consumed keys are normalized by the trusted compiler adapter before this pure function.
 * authorizedFragments comes from the selected executor's durable authorization ledger,
 * not PR body text, labels, actor names or an unverified caller boolean.
 */
export function proveManagedPatchRelease({ repository, head, plan, base, before, after, authorizedFragments, changelogPath = 'CHANGELOG.md', publicationAuthorization }) {
  requireProof(/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repository ?? '')
    && /^[a-f0-9]{40}$/.test(head ?? '') && /^[a-f0-9]{40}$/.test(base ?? '')
    && /^[a-f0-9]{64}$/.test(plan.id ?? ''), 'Complete immutable release identity required.');
  requireProof(plan.base_sha === base && plan.impact === 'patch' && plan.mode === 'release'
    && plan.resuming === false && equal(plan.historical_versions, []), 'Not a fresh PATCH-only release plan.');
  const semver = (text) => { const match = typeof text === 'string' && /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.exec(text);
    requireProof(match, 'Stable release versions required.'); return match.slice(1).map(BigInt); };
  const a = semver(plan.current_version), b = semver(plan.next_version);
  requireProof(a[0] === b[0] && a[1] === b[1] && b[2] === a[2] + 1n, 'Release is not the next PATCH.');
  requireProof(Object.keys(authorizedFragments).length > 0 && equal(plan.consumed, authorizedFragments), 'Unrelated or missing authorized fragments.');
  const digest = /^Changelog-Output: ([a-f0-9]{64})$/m.exec(plan.commit_message ?? '')?.[1];
  requireProof(digest, 'Trusted compiler output digest missing.');
  for (const [path, expected] of Object.entries(authorizedFragments)) {
    requireProof(before[path]?.mode === '100644' && hash(before[path].bytes) === expected && !after[path], 'Fragment receipt/deletion mismatch.');
  }
  requireProof(after[changelogPath]?.mode === '100644' && hash(after[changelogPath].bytes) === digest, 'Compiled history bytes mismatch.');
  const changed = [...new Set([...Object.keys(before), ...Object.keys(after)])].filter((path) => !equal(before[path], after[path])).sort();
  requireProof(equal(changed, [changelogPath, ...Object.keys(authorizedFragments)].sort()), 'Version PR contains unrelated changes.');
  // Authorization is a ledger record bound to the complete exact compiled transaction.
  requireProof(publicationAuthorization?.repository === repository && publicationAuthorization.head === head
    && publicationAuthorization.base === base
    && publicationAuthorization.planId === plan.id && publicationAuthorization.outputSha256 === digest
    && publicationAuthorization.nextVersion === plan.next_version, 'Exact publication authorization missing.');
  return { repository, head, planId: plan.id, base, outputSha256: digest, nextVersion: plan.next_version, consumed: authorizedFragments };
}

/** Build release evidence from immutable GitHub blobs, trusted compilation and a durable ledger.
 * The caller owns a clean read-only base checkout and the existing managed-PR identity
 * validator. No consumer executable, PR trailer or PR title establishes authorization.
 */
export function managedVersionInspector({ github, ledger, compiler, requireManagedOwnership }) {
  return async (candidate) => {
    requireProof(await requireManagedOwnership(candidate) === true, 'Existing managed ownership inspection rejected the PR.');
    const plan = await compiler(candidate);
    const records = await ledger.releaseAuthorization(candidate.repository, candidate.base, candidate.head, plan.id);
    const [owner, repo] = candidate.repository.split('/');
    const snapshots = [];
    for (const ref of [candidate.base, candidate.head]) {
      const commit = (await github.rest.git.getCommit({ owner, repo, commit_sha: ref })).data;
      const tree = (await github.rest.git.getTree({ owner, repo, tree_sha: commit.tree.sha, recursive: '1' })).data;
      requireProof(tree.truncated === false, 'Incomplete release tree.');
      const snapshot = Object.create(null);
      for (const entry of tree.tree.filter((entry) => entry.type !== 'tree')) {
        requireProof(!Object.hasOwn(snapshot, entry.path), 'Duplicate release path.');
        snapshot[entry.path] = { mode: entry.mode, sha: entry.sha, type: entry.type };
      }
      snapshots.push(snapshot);
    }
    const [before, after] = snapshots;
    const changed = [...new Set([...Object.keys(before), ...Object.keys(after)])].filter((path) => !equal(before[path], after[path]));
    for (const path of changed) for (const snapshot of snapshots) {
      const entry = snapshot[path];
      if (!entry) continue;
      requireProof(entry.mode === '100644' && entry.type === 'blob', 'Unsupported release Git mode.');
      const blob = (await github.rest.git.getBlob({ owner, repo, file_sha: entry.sha })).data;
      requireProof(blob.encoding === 'base64' && blob.size <= 1024 * 1024, 'Incomplete release blob.');
      entry.bytes = Buffer.from(blob.content, 'base64');
      requireProof(entry.bytes.length === blob.size, 'Truncated release blob.');
    }
    return { plan, before, after, authorizedFragments: records.fragments,
      publicationAuthorization: records.publication, changelogPath: records.changelogPath };
  };
}

/** Use the existing signed managed-PR policy, not a caller-supplied ownership flag.
 * headSnapshot must be an inert clean checkout built by the trusted integrator;
 * credentials are passed only to this trusted runtime's read-only API operation.
 */
export function managedOwnershipInspector({ runtimeCli, headSnapshot, managedBranch, actor, baseBranch, readToken }, run = execFileSync) {
  return async (candidate) => {
    requireProof(isAbsolute(runtimeCli) && isAbsolute(headSnapshot)
      && relative(headSnapshot, runtimeCli).startsWith('..' + sep), 'Trusted ownership runtime must be outside consumer data.');
    const options = { cwd: headSnapshot, encoding: 'utf8', timeout: 120000, maxBuffer: 8 * 1024 * 1024,
      env: { PATH: process.env.PATH, TMPDIR: process.env.TMPDIR || '/tmp', HOME: process.env.TMPDIR || '/tmp',
        FF_CHANGELOG_TOKEN: readToken, GIT_CONFIG_NOSYSTEM: '1', GIT_CONFIG_GLOBAL: '/dev/null', GIT_OPTIONAL_LOCKS: '0' } };
    requireProof(run('git', ['rev-parse', 'HEAD'], options).trim() === candidate.head
      && run('git', ['status', '--porcelain', '--untracked-files=all'], options) === '', 'Managed head snapshot mismatch.');
    const report = parseJson(run('php', ['-d', 'xdebug.mode=off', runtimeCli, 'github', 'pr-status',
      '--no-interaction', `--cwd=${headSnapshot}`, '--template=keep-a-changelog', '--source=tags',
      '--resolve-pull-requests=false', `--repository=${candidate.repository}`, `--pull-request=${candidate.pull_request}`,
      `--expected-head-sha=${candidate.head}`, `--since=${candidate.base}`, `--managed-branch=${managedBranch}`,
      `--automation-actor=${actor}`, `--base-branch=${baseBranch}`, '--publish-status=false'], options));
    return report.status === 'valid' && report.kind === 'managed-version' && report.head_sha === candidate.head
      && report.base_sha === candidate.base && report.pull_request === candidate.pull_request;
  };
}
