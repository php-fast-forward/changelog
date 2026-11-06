import { mergeExpectedHead } from './dependabot-gates.mjs';

/** Native exclusion: GitHub restricts the protected branch to one authenticated App.
 * authenticate() is an existing trusted App-token provider, not consumer PR code.
 * It must bind the returned SDK to the App identity established while minting its token.
 * No credentials, variables, permissions or branch rules are provisioned by this adapter.
 */
export function nativeGitHubBoundary({ authenticate, executor = 'github-actions' }) {
  return { async run(identity, operation) {
    const session = await authenticate(identity.repository);
    const { github, appId } = session;
    if (!Number.isSafeInteger(appId) || appId <= 0) throw Error('Authenticated App identity unavailable.');
    const [owner, repo] = identity.repository.split('/');
    const guard = async () => {
      const selected = (await github.request('GET /repos/{owner}/{repo}/actions/variables/{name}',
        { owner, repo, name: 'DEPENDABOT_MERGE_EXECUTOR' })).data.value;
      if (selected !== `${executor}:${appId}` || identity.executor !== executor) throw Error('Executor ownership mismatch.');
      const pr = (await github.rest.pulls.get({ owner, repo, pull_number: identity.pullRequest })).data;
      if (pr.head.sha !== identity.head || pr.base.sha !== identity.base) throw Error('Ownership snapshot changed.');
      const protection = (await github.request('GET /repos/{owner}/{repo}/branches/{branch}/protection',
        { owner, repo, branch: pr.base.ref })).data;
      const restrictions = protection.restrictions;
      if (!protection.enforce_admins?.enabled || !protection.required_status_checks?.strict
        || protection.allow_force_pushes?.enabled !== false || protection.allow_deletions?.enabled !== false
        || !restrictions || restrictions.users?.length !== 0 || restrictions.teams?.length !== 0
        || restrictions.apps?.length !== 1 || restrictions.apps[0].id !== appId) {
        throw Error('Exclusive native App restrictions and strict up-to-date protection are required.');
      }
      const bypass = protection.required_pull_request_reviews?.bypass_pull_request_allowances;
      if (bypass && ['users', 'teams', 'apps'].some((key) => bypass[key]?.length)) throw Error('Review bypass present.');
    };
    await guard();
    return operation({ async merge(command) {
      if (command.repository !== identity.repository || command.pullRequest !== identity.pullRequest
        || command.expectedHead !== identity.head || command.expectedBase !== identity.base) throw Error('Merge command escaped owned snapshot.');
      await guard();
      // GitHub atomically enforces current branch restrictions/up-to-date requirements
      // and expected HEAD. This does not implement a cross-service software lease.
      return mergeExpectedHead(github, command);
    } });
  } };
}
