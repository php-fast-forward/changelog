import { inspectGitHubGates } from './dependabot-gates.mjs';
import { evaluate } from './dependabot-patch.mjs';

/** One iteration, never a daemon. Boundary owns credentialed side effects and fencing. */
export async function advanceDependencyUpdate({ github, context, policy, boundary, repair, ledger }) {
  const wait = (reason) => ({ state: 'waiting', reason, merge_authorized: false });
  if (!policy || policy.optIn !== true) return { state: 'blocked', reason: 'repository-not-opted-in' };
  if (!['evaluate', 'execute'].includes(policy.mode)) return { state: 'blocked', reason: 'unsupported-mode' };
  let candidate;
  try {
    candidate = await evaluate({ github, context, optedIn: true, mode: 'evaluate',
      checks: policy.checks, ecosystem: policy.ecosystem, directory: policy.directory });
  } catch (error) {
    return { state: 'blocked', reason: 'candidate-proof-failed', diagnostic: error.message };
  }
  if (policy.mode === 'evaluate') return { state: 'evaluated', candidate };
  if (!boundary || typeof boundary.run !== 'function') return wait('shared-fenced-boundary-unavailable');
  // No token or merge callback escapes the boundary. Its implementation MUST serialize all
  // executors and enforce ownership, fencing, base stability and revocation at the write.
  let mergeAttempted = false;
  try { return await boundary.run({ repository: candidate.repository, executor: policy.executor,
    pullRequest: candidate.pull_request, head: candidate.head, base: candidate.base }, async (session) => {
    if (!session || typeof session.merge !== 'function') {
      return wait('boundary-session-incomplete');
    }
    const inspected = await inspectGitHubGates(github, candidate, policy.pairChecks);
    if (inspected.state !== 'ready') {
      if (inspected.state === 'repairable' && repair && typeof repair.request === 'function') {
        const request = await repair.request({ repository: candidate.repository,
          pullRequest: candidate.pull_request, head: candidate.head, base: candidate.base,
          failures: inspected.failures });
        return { state: 'repair-requested', request, retry: 'fresh-event-required' };
      }
      return { ...inspected, merge_authorized: false };
    }
    // Re-prove data and review/security checks inside ownership immediately before merging.
    const fresh = await evaluate({ github, context, optedIn: true, mode: 'evaluate',
      checks: policy.checks, ecosystem: policy.ecosystem, directory: policy.directory });
    const current = await inspectGitHubGates(github, fresh, policy.pairChecks);
    if (current.state !== 'ready') return { ...current, merge_authorized: false };
    mergeAttempted = true;
    const result = await session.merge({ repository: fresh.repository, pullRequest: fresh.pull_request,
      expectedHead: fresh.head, expectedBase: fresh.base, method: 'squash' });
    if (result?.merged !== true) return { state: 'waiting', reason: 'merge-not-confirmed', result };
    if (!/^[a-f0-9]{40}$/.test(result.sha ?? '')) return { state: 'reconciliation-required', reason: 'merge-commit-not-confirmed' };
    const receipt = { repository: fresh.repository, pullRequest: fresh.pull_request,
      head: fresh.head, base: fresh.base, mergeCommit: result.sha, changes: fresh.changes, checks: fresh.checks };
    if (ledger) await ledger.recordMergedPatch(receipt);
    return { state: 'merged', receipt, repository: fresh.repository, pullRequest: fresh.pull_request,
      head: fresh.head, base: fresh.base, mergeCommit: result.sha };
  }); } catch (error) {
    return { state: mergeAttempted ? 'reconciliation-required' : 'waiting',
      reason: error.message, retry: mergeAttempted ? 'inspect-remote-before-any-retry' : 'fresh-event-required' };
  }
}

/** Managed version PRs require real compiler/blob evidence and durable authorization,
 * never their title/branch name. inspectVersion is a trusted runtime/ledger adapter
 * using compileVersionPlan; it must also invoke the existing managed-PR ownership validator.
 * Absent adapters remain an explicit waiting state, never implicit release authority.
 */
export async function advanceManagedVersion({ github, context, policy, boundary, inspectVersion }) {
  if (policy?.optIn !== true || policy.mode !== 'execute') return { state: 'waiting', reason: 'managed-execution-not-selected' };
  if (!boundary || !inspectVersion) return { state: 'waiting', reason: 'managed-runtime-or-authorization-ledger-unavailable' };
  let mergeAttempted = false;
  try {
  const { proveManagedPatchRelease } = await import('./dependabot-release-proof.mjs');
  const pr = (await github.rest.pulls.get({ ...context.repo, pull_number: context.payload.pull_request.number })).data;
  const repository = `${context.repo.owner}/${context.repo.repo}`;
  if (pr.user?.login !== policy.managedActor || pr.user?.id !== policy.managedActorId || pr.user?.type !== 'Bot'
    || pr.head.repo.full_name !== repository || pr.base.repo.full_name !== repository || pr.draft || pr.state !== 'open') {
    return { state: 'blocked', reason: 'managed-identity-mismatch' };
  }
  const candidate = { repository, pull_request: pr.number, head: pr.head.sha, base: pr.base.sha };
  return await boundary.run({ repository, executor: policy.executor, pullRequest: pr.number, head: pr.head.sha, base: pr.base.sha }, async session => {
    const evidence = await inspectVersion(candidate);
    const proof = proveManagedPatchRelease({ ...evidence, repository, head: candidate.head, base: candidate.base });
    const gates = await inspectGitHubGates(github, candidate, policy.pairChecks);
    if (gates.state !== 'ready') return gates;
    // Recompute the compiler/blob/receipt proof after live checks; no cached authorization.
    proveManagedPatchRelease({ ...await inspectVersion(candidate), repository, head: candidate.head, base: candidate.base });
    mergeAttempted = true;
    const result = await session.merge({ repository, pullRequest: pr.number, expectedHead: candidate.head,
      expectedBase: candidate.base, method: 'squash' });
    return { state: result.merged === true ? (/^[a-f0-9]{40}$/.test(result.sha ?? '') ? 'merged' : 'reconciliation-required') : 'waiting', result, proof };
  }); } catch (error) {
    return { state: mergeAttempted ? 'reconciliation-required' : 'blocked', reason: error.message,
      retry: mergeAttempted ? 'inspect-remote-before-any-retry' : 'fresh-event-required' };
  }
}
