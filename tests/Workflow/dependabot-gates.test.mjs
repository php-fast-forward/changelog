import test from 'node:test';
import assert from 'node:assert/strict';
import { inspectGitHubGates, mergeExpectedHead } from '../../.github/scripts/dependabot-gates.mjs';
const head = 'a'.repeat(40), base = 'b'.repeat(40), integration = 'c'.repeat(40);
function fixture() {
  const candidate = { repository: 'fixture/project', pull_request: 1, head, base };
  const pr = { state: 'open', head: { sha: head }, base: { sha: base, ref: 'main' }, merge_commit_sha: integration };
  const checks = [{ name: 'security', app_id: 1 }, { name: 'viability', app_id: 2 }];
  const protection = { enforce_admins: { enabled: true }, required_status_checks: { strict: true, checks: [{context:'tests',app_id:3},...checks.map(check=>({context:check.name,app_id:check.app_id}))] },
    required_pull_request_reviews: { dismiss_stale_reviews: true, require_last_push_approval: true, required_approving_review_count: 1 },
    required_conversation_resolution: { enabled: true } };
  const runs = [...checks.map(c=>({name:c.name,app:{id:c.app_id},head_sha:head,status:'completed',conclusion:'success'})), ...checks.map(c=>({name:c.name,app:{id:c.app_id},head_sha:integration,status:'completed',conclusion:'success'})),
    {name:'tests',app:{id:3},head_sha:head,status:'completed',conclusion:'success'}];
  const threads = { nodes: [{isResolved:true}], pageInfo:{hasNextPage:false} };
  const github = { rest: {pulls:{get:async()=>({data:structuredClone(pr)}),merge:async request=>({data:{merged:true,sha:request.sha}})},
    git:{getCommit:async()=>({data:{parents:[{sha:base},{sha:head}]}})},checks:{listForRef:async request=>runs.filter(r=>r.head_sha===request.ref)}},
    request:async()=>({data:protection}),paginate:async(fn,request)=>typeof fn==='string'?[]:fn(request),
    graphql:async()=>({repository:{pullRequest:{headRefOid:head,baseRefOid:base,reviewDecision:'APPROVED',mergeStateStatus:'CLEAN',mergeable:'MERGEABLE',reviews:{nodes:[{state:'APPROVED',author:{__typename:'User',login:'reviewer'},commit:{oid:head}}],pageInfo:{hasNextPage:false}},reviewThreads:threads}}}) };
  return {github,candidate,checks,pr,runs,threads,protection};
}
test('live gates inspect exact integration pair, required check and reviews',async()=>{const f=fixture();assert.equal((await inspectGitHubGates(f.github,f.candidate,f.checks)).state,'ready');});
for(const [name,mutate]of [
  ['base changed',f=>{f.pr.base.sha=head;}],['unresolved thread',f=>{f.threads.nodes[0].isResolved=false;}],
  ['spoofed pair producer',f=>{f.runs[0].app.id=8;}],['missing required check',f=>{f.runs.pop();}],
  ['stale approvals allowed',f=>{f.protection.required_pull_request_reviews.dismiss_stale_reviews=false;}],
  ['unknown rules',f=>{f.github.paginate=async()=>[{type:'merge_queue'}];}],
  ['inaccessible protection',f=>{f.github.request=async()=>{throw Error('403');};}],
  ['wrong integration parents',f=>{f.github.rest.git.getCommit=async()=>({data:{parents:[{sha:head}]}});}],
])test(`gate blocks ${name}`,async()=>{const f=fixture();mutate(f);assert.notEqual((await inspectGitHubGates(f.github,f.candidate,f.checks)).state,'ready');});
test('merge adapter sends expected HEAD and never merges a changed base',async()=>{const f=fixture();const command={repository:'fixture/project',pullRequest:1,expectedHead:head,expectedBase:base,method:'squash'};assert.equal((await mergeExpectedHead(f.github,command)).merged,true);f.pr.base.sha=head;assert.equal((await mergeExpectedHead(f.github,command)).merged,false);});

test('missing effective rule parameters fail closed',async()=>{const f=fixture();f.github.paginate=async(fn,request)=>typeof fn==='string'?[{type:'required_status_checks'}]:fn(request);assert.equal((await inspectGitHubGates(f.github,f.candidate,f.checks)).state,'blocked');});
