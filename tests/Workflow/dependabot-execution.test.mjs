import test from 'node:test';
import assert from 'node:assert/strict';
import { sdkFixture } from './dependabot-patch.test.mjs';
import { advanceDependencyUpdate } from '../../.github/scripts/dependabot-execution.mjs';
test('execute waits without ownership boundary and cannot call merge', async () => {
  const f = sdkFixture();
  const policy = { optIn: true, mode: 'execute', checks: f.checks, ecosystem: 'composer', directory: '.', executor: 'github' };
  const result = await advanceDependencyUpdate({ ...f, policy });
  assert.equal(result.state, 'waiting');
  assert.equal(result.reason, 'shared-fenced-boundary-unavailable');
});
test('evaluation mode never enters a supplied execution boundary', async () => {
  const f = sdkFixture();
  const policy = { optIn: true, mode: 'evaluate', checks: f.checks, ecosystem: 'composer', directory: '.' };
  const result = await advanceDependencyUpdate({ ...f, policy, boundary: {run:()=>assert.fail('unexpected write boundary')} });
  assert.equal(result.state, 'evaluated');
});
test('execute inspects real live gates even if supplied session claims it can merge', async () => {
  const f = sdkFixture(); let merged = false;
  const policy = { optIn: true, mode: 'execute', checks: f.checks, ecosystem: 'composer', directory: '.', pairChecks: f.checks };
  const result = await advanceDependencyUpdate({ ...f, policy, boundary: {run:async(_identity, operation)=>operation({merge:async()=>{merged=true;}})} });
  assert.notEqual(result.state, 'merged');
  assert.equal(merged,false);
});

import { nativeGitHubBoundary } from '../../.github/scripts/dependabot-native-boundary.mjs';
function executableFixture() {
  const f = sdkFixture(); const integration = 'd'.repeat(40);
  const head = f.pr.head.sha, base = f.pr.base.sha;
  f.pr.base.ref = 'main'; f.pr.merge_commit_sha = integration;
  const protection = { enforce_admins:{enabled:true},required_status_checks:{strict:true,checks:[{context:'tests',app_id:3},...f.checks.map(check=>({context:check.name,app_id:check.app_id}))]},
    required_pull_request_reviews:{dismiss_stale_reviews:true,require_last_push_approval:true,required_approving_review_count:1},
    required_conversation_resolution:{enabled:true},allow_force_pushes:{enabled:false},allow_deletions:{enabled:false},
    restrictions:{users:[],teams:[],apps:[{id:7}]}};
  const pairRuns = f.runs.map(run=>({...run,head_sha:integration}));
  const required = {name:'tests',app:{id:3},head_sha:head,status:'completed',conclusion:'success'};
  f.github.rest.checks.listForRef = async request=>request.ref===integration?pairRuns:[...f.runs,required];
  const getCommit = f.github.rest.git.getCommit;
  f.github.rest.git.getCommit = async request=>request.commit_sha===integration?{data:{parents:[{sha:head},{sha:base}]}}:getCommit(request);
  f.github.request = async route=>({data:route.includes('/variables/')?{value:'github-actions:7'}:protection});
  const paginate = f.github.paginate;
  f.github.paginate = async(fn,request)=>typeof fn==='string'?[]:paginate(fn,request);
  f.github.graphql = async()=>({repository:{pullRequest:{headRefOid:head,baseRefOid:base,
    reviewDecision:'APPROVED',mergeStateStatus:'CLEAN',mergeable:'MERGEABLE',
    reviews:{nodes:[{state:'APPROVED',author:{__typename:'User',login:'reviewer'},commit:{oid:head}}],pageInfo:{hasNextPage:false}},
    reviewThreads:{nodes:[],pageInfo:{hasNextPage:false}}}}});
  const writes=[];
  f.github.rest.pulls.merge = async command=>{writes.push(command);return {data:{merged:true,sha:'e'.repeat(40)}};};
  const policy={optIn:true,mode:'execute',checks:f.checks,pairChecks:f.checks,ecosystem:'composer',directory:'.',executor:'github-actions'};
  const boundary=nativeGitHubBoundary({authenticate:async()=>({github:f.github,appId:7})});
  return {...f,policy,boundary,writes,protection,required};
}
test('full execution re-proves live gates and merges through native exclusive boundary',async()=>{
  const f=executableFixture();const result=await advanceDependencyUpdate(f);
  assert.equal(result.state,'merged');assert.equal(f.writes.length,1);
  assert.equal(f.writes[0].sha,f.pr.head.sha);assert.equal(f.writes[0].merge_method,'squash');
});
test('another executor App cannot use the merge boundary',async()=>{
  const f=executableFixture();f.protection.restrictions.apps[0].id=8;
  assert.equal((await advanceDependencyUpdate(f)).state,'waiting');assert.equal(f.writes.length,0);
});
test('uncertain merge response requires reconciliation, never blind retry',async()=>{
  const f=executableFixture();f.github.rest.pulls.merge=async()=>{throw Error('timeout after write');};
  assert.equal((await advanceDependencyUpdate(f)).state,'reconciliation-required');
});
test('required CI failure requests repair through explicit handler without merging',async()=>{
  const f=executableFixture();f.required.conclusion='failure';let requests=0;
  f.repair={request:async request=>{requests++;assert.equal(request.head,f.pr.head.sha);return 'repair-1';}};
  assert.equal((await advanceDependencyUpdate(f)).state,'repair-requested');assert.equal(requests,1);assert.equal(f.writes.length,0);
});
for(const field of ['repository','pullRequest','expectedHead','expectedBase'])test(`native boundary rejects escaped ${field}`,async()=>{
 const f=executableFixture();await assert.rejects(f.boundary.run({repository:'fixture/consumer',executor:'github-actions',pullRequest:f.pr.number,head:f.pr.head.sha,base:f.pr.base.sha},session=>session.merge({repository:'fixture/consumer',pullRequest:f.pr.number,expectedHead:f.pr.head.sha,expectedBase:f.pr.base.sha,method:'squash',[field]:'wrong'})),/escaped owned snapshot/);assert.equal(f.writes.length,0);
});
test('selector change blocks a new boundary operation',async()=>{
 const f=executableFixture();const request=f.github.request;f.github.request=async(route,args)=>route.includes('/variables/')?{data:{value:'n8n:8'}}:request(route,args);
 assert.equal((await advanceDependencyUpdate(f)).state,'waiting');assert.equal(f.writes.length,0);
});

import { advanceManagedVersion } from '../../.github/scripts/dependabot-execution.mjs';
import { createHash } from 'node:crypto';
function managedFixture() {
 const f=executableFixture();f.pr.user={login:'release[bot]',id:17,type:'Bot'};
 Object.assign(f.policy,{managedActor:'release[bot]',managedActorId:17});
 const digest=bytes=>createHash('sha256').update(bytes).digest('hex');const note=Buffer.from('authorized note'),output=Buffer.from('compiled');
 const fragments={'.changelog/patch.md':digest(note)},id='f'.repeat(64);
 f.inspectVersion=async()=>({plan:{id,base_sha:f.pr.base.sha,impact:'patch',mode:'release',resuming:false,historical_versions:[],current_version:'1.2.3',next_version:'1.2.4',consumed:fragments,commit_message:`Changelog-Output: ${digest(output)}`},
 authorizedFragments:fragments,before:{'CHANGELOG.md':{mode:'100644',bytes:Buffer.from('old')},'.changelog/patch.md':{mode:'100644',bytes:note}},after:{'CHANGELOG.md':{mode:'100644',bytes:output}},
 publicationAuthorization:{repository:'fixture/consumer',head:f.pr.head.sha,base:f.pr.base.sha,planId:id,outputSha256:digest(output),nextVersion:'1.2.4'}});
 return f;
}
test('managed version flow applies compiler/authorization proof before actual merge adapter',async()=>{const f=managedFixture();assert.equal((await advanceManagedVersion(f)).state,'merged');assert.equal(f.writes.length,1);});
test('managed version flow blocks piggyback content',async()=>{const f=managedFixture();const inspect=f.inspectVersion;f.inspectVersion=async()=>{const p=await inspect();p.after['other.php']={mode:'100644',bytes:Buffer.from('evil')};return p;};assert.equal((await advanceManagedVersion(f)).state,'blocked');assert.equal(f.writes.length,0);});
test('managed version timeout after write requires reconciliation',async()=>{const f=managedFixture();f.github.rest.pulls.merge=async()=>{throw Error('response lost');};assert.equal((await advanceManagedVersion(f)).state,'reconciliation-required');});

test('actual execution workflow script drives the tested native merge path', {skip:!process.env.FF_EXEC_SCRIPT}, async()=>{
 const f=executableFixture(),saved={...process.env};const outputs={};
 Object.assign(process.env,{GITHUB_WORKSPACE:new URL('../..',import.meta.url).pathname,FF_NUMBER:'7',FF_OPT_IN:'true',FF_MODE:'execute',FF_ECOSYSTEM:'composer',FF_DIRECTORY:'.',FF_CHECKS:JSON.stringify(f.checks),FF_PAIR_CHECKS:JSON.stringify(f.checks),FF_APP_SLUG:'fixture-app'});
 f.github.rest.apps={getBySlug:async()=>({data:{id:7}})};f.context.eventName='workflow_dispatch';
 const summary={addCodeBlock(){return this;},async write(){}};
 try{const AsyncFunction=Object.getPrototypeOf(async function(){}).constructor;
 await new AsyncFunction('github','context','core',process.env.FF_EXEC_SCRIPT)(f.github,f.context,{setOutput:(name,value)=>{outputs[name]=JSON.parse(value);},summary});
 assert.equal(outputs.result.state,'merged');assert.equal(f.writes.length,1);
 }finally{process.env=saved;}
});
test('actual execution guard rejects untrusted events and non-default refs',{skip:!process.env.FF_EXEC_GUARD},async()=>{
 const {spawnSync}=await import('node:child_process');
 for(const [event,ref,expected]of [['workflow_dispatch','refs/heads/main',0],['workflow_run','refs/heads/main',0],['pull_request_target','refs/heads/main',1],['pull_request','refs/heads/main',1],['workflow_run','refs/heads/feature',1]]){
 const run=spawnSync('bash',['-c',process.env.FF_EXEC_GUARD],{env:{PATH:process.env.PATH,FF_EVENT:event,FF_REF:ref,FF_DEFAULT:'main',FF_RUNTIME_REPO:'fixture/runtime',FF_RUNTIME_SHA:'a'.repeat(40)}});
 assert.equal(run.status,expected);
 }
});

test('merge confirmation without a commit identity requires reconciliation',async()=>{const f=executableFixture();f.github.rest.pulls.merge=async()=>({data:{merged:true}});assert.equal((await advanceDependencyUpdate(f)).state,'reconciliation-required');});
