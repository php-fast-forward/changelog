/** Inspect live GitHub protection, reviews, threads and every required check; unknowns block. */
export async function inspectGitHubGates(github, candidate, pairChecks) {
  const [owner, repo] = candidate.repository.split('/');
  const request = { owner, repo, pull_number: candidate.pull_request };
  const wait = (reason) => ({ state: 'waiting', reason });
  const blocked = (reason) => ({ state: 'blocked', reason });
  const matches = (pr) => pr.state === 'open' && !pr.draft && !pr.merged
    && pr.head.sha === candidate.head && pr.base.sha === candidate.base;
  try {
    const pr = (await github.rest.pulls.get(request)).data;
    if (!matches(pr)) return wait('head-or-base-changed');
    if (!Array.isArray(pairChecks) || pairChecks.length < 2 || pairChecks.some((check) =>
      typeof check.name !== 'string' || check.name.length === 0 || !Number.isSafeInteger(check.app_id) || check.app_id <= 0)
      || new Set(pairChecks.map((check) => check.name)).size !== pairChecks.length) return blocked('exact-pair-check-policy-missing');
    const integration = pr.merge_commit_sha;
    if (!/^[a-f0-9]{40}$/.test(integration ?? '')) return wait('test-merge-not-available');
    const commit = (await github.rest.git.getCommit({ owner, repo, commit_sha: integration })).data;
    const parents = commit.parents?.map((parent) => parent.sha).sort();
    if (JSON.stringify(parents) !== JSON.stringify([candidate.base, candidate.head].sort())) return blocked('test-merge-parents-mismatch');
    const integrationRuns = await github.paginate(github.rest.checks.listForRef, { owner, repo, ref: integration, filter: 'latest', per_page: 100 });
    for (const check of pairChecks) {
      const found = integrationRuns.filter((run) => run.name === check.name);
      if (found.length !== 1 || found[0].app?.id !== check.app_id || found[0].head_sha !== integration
        || found[0].status !== 'completed' || found[0].conclusion !== 'success') return wait('exact-pair-checks-pending');
    }
    const branch = pr.base.ref;
    const protection = (await github.request('GET /repos/{owner}/{repo}/branches/{branch}/protection', { owner, repo, branch })).data;
    const rules = await github.paginate('GET /repos/{owner}/{repo}/rules/branches/{branch}', { owner, repo, branch, per_page: 100 });
    // Only audited rule types can be represented by this gate. New rules require an adapter update.
    const known = ['required_status_checks', 'pull_request', 'non_fast_forward', 'deletion', 'required_signatures', 'required_linear_history'];
    if (!rules.every((rule) => known.includes(rule.type))) return blocked('unsupported-effective-rule');
    if (rules.some((rule) => ['required_status_checks', 'pull_request'].includes(rule.type) && !rule.parameters)
      || rules.some((rule) => rule.type === 'required_status_checks'
        && !Array.isArray(rule.parameters.required_status_checks))) return blocked('incomplete-effective-rule');
    const reviews = protection.required_pull_request_reviews;
    if (!protection.enforce_admins?.enabled || !protection.required_status_checks?.strict
      || !reviews?.dismiss_stale_reviews || !reviews?.require_last_push_approval
      || !(reviews.required_approving_review_count > 0)
      || !protection.required_conversation_resolution?.enabled) return blocked('insufficient-enforced-protection');
    const bypass = reviews.bypass_pull_request_allowances;
    if (bypass && ['users', 'teams', 'apps'].some((key) => bypass[key]?.length)) return blocked('review-bypass-present');
    const required = [...(protection.required_status_checks.checks ?? []), ...rules
      .filter((rule) => rule.type === 'required_status_checks')
      .flatMap((rule) => (rule.parameters?.required_status_checks ?? []).map((check) => ({ context: check.context, app_id: check.integration_id })))];
    if ([...pairChecks, ...(candidate.checks ?? [])].some((check) => !required.some((entry) =>
      entry.context === check.name && entry.app_id === check.app_id))) return blocked('security-checks-not-server-required');
    if (required.length === 0 || required.some((check) => typeof check.context !== 'string'
      || !Number.isSafeInteger(check.app_id) || check.app_id <= 0)) return blocked('required-check-producer-not-bound');
    if ((protection.required_status_checks.contexts ?? []).some((context) => !required.some((check) => check.context === context))) return blocked('legacy-required-context-not-bound');
    const runs = await github.paginate(github.rest.checks.listForRef, { owner, repo, ref: candidate.head, filter: 'latest', per_page: 100 });
    for (const check of required) {
      const found = runs.filter((run) => run.name === check.context);
      if (found.length === 1 && found[0].app?.id === check.app_id && found[0].head_sha === candidate.head
        && found[0].status === 'completed' && found[0].conclusion === 'failure') return {
          state: 'repairable', failures: [{ name: check.context, appId: check.app_id, checkRunId: found[0].id, head: candidate.head }],
        };
      if (found.length !== 1 || found[0].app?.id !== check.app_id || found[0].head_sha !== candidate.head
        || found[0].status !== 'completed' || found[0].conclusion !== 'success') return wait('required-check-not-successful');
    }
    let cursor = null;
    do {
      const data = await github.graphql(`query($owner:String!,$repo:String!,$number:Int!,$cursor:String){
        repository(owner:$owner,name:$repo){pullRequest(number:$number){headRefOid baseRefOid
          reviewDecision mergeStateStatus mergeable reviews(first:100){nodes{state author{__typename login} commit{oid}} pageInfo{hasNextPage}} reviewThreads(first:100,after:$cursor){
            nodes{isResolved} pageInfo{hasNextPage endCursor}}}}}`, { owner, repo, number: candidate.pull_request, cursor });
      const current = data.repository?.pullRequest;
      if (!current || current.headRefOid !== candidate.head || current.baseRefOid !== candidate.base) return wait('head-or-base-changed');
      if (current.reviewDecision !== 'APPROVED' || current.mergeStateStatus !== 'CLEAN' || current.mergeable !== 'MERGEABLE') return wait('reviews-or-mergeability-pending');
      const approvals = current.reviews;
      if (!approvals?.nodes || approvals.pageInfo?.hasNextPage !== false) return blocked('incomplete-approval-evidence');
      const latest = new Map();
      for (const review of approvals.nodes) {
        if (review.author?.__typename === 'User' && typeof review.author.login === 'string'
          && review.state !== 'COMMENTED' && review.state !== 'PENDING') latest.set(review.author.login, review);
      }
      if ([...latest.values()].filter((review) => review.state === 'APPROVED'
        && review.commit?.oid === candidate.head).length < reviews.required_approving_review_count) return wait('exact-head-human-approval-pending');
      const threads = current.reviewThreads;
      if (!threads?.nodes || threads.nodes.some((thread) => thread.isResolved !== true)) return wait('review-threads-unresolved');
      const page = threads.pageInfo;
      if (!page || (page.hasNextPage && (!page.endCursor || page.endCursor === cursor))) return blocked('incomplete-review-threads');
      cursor = page.hasNextPage ? page.endCursor : null;
    } while (cursor);
    const finalPr = (await github.rest.pulls.get(request)).data;
    if (!matches(finalPr) || finalPr.merge_commit_sha !== integration) return wait('head-base-or-test-merge-changed');
    return { state: 'ready', head: candidate.head, base: candidate.base, requiredChecks: required };
  } catch (error) {
    return blocked('live-gate-inspection-failed:' + error.message);
  }
}

/** Credentialed SDK operation for use ONLY inside an accepted fenced boundary. */
export async function mergeExpectedHead(github, command) {
  const [owner, repo] = command.repository.split('/');
  const pr = (await github.rest.pulls.get({ owner, repo, pull_number: command.pullRequest })).data;
  if (pr.head.sha !== command.expectedHead || pr.base.sha !== command.expectedBase) return { merged: false, reason: 'snapshot-changed' };
  return (await github.rest.pulls.merge({ owner, repo, pull_number: command.pullRequest,
    sha: command.expectedHead, merge_method: command.method })).data;
}
