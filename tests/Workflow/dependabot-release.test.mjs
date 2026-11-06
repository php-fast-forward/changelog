import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { proveManagedPatchRelease, compileVersionPlan } from '../../.github/scripts/dependabot-release-proof.mjs';
const hash=bytes=>createHash('sha256').update(bytes).digest('hex');
function fixture(){const bytes=Buffer.from('approved patch note');const output=Buffer.from('compiled history');
const fragments={'.changelog/dependency.md':hash(bytes)};const base='a'.repeat(40),head='b'.repeat(40);
return {repository:'fixture/repo',head,base,authorizedFragments:fragments,
plan:{id:'f'.repeat(64),base_sha:base,mode:'release',impact:'patch',current_version:'1.2.3',next_version:'1.2.4',resuming:false,historical_versions:[],consumed:fragments,commit_message:`Changelog-Output: ${hash(output)}`},
before:{'CHANGELOG.md':{mode:'100644',bytes:Buffer.from('history')},'.changelog/dependency.md':{mode:'100644',bytes}},
after:{'CHANGELOG.md':{mode:'100644',bytes:output}},publicationAuthorization:{repository:'fixture/repo',head,base,planId:'f'.repeat(64),outputSha256:hash(output),nextVersion:'1.2.4'}};}
test('version proof accepts exactly compiled authorized PATCH fragments and publication',()=>assert.equal(proveManagedPatchRelease(fixture()).nextVersion,'1.2.4'));
for(const [name,mutate]of [
['piggyback fragment',f=>{f.plan.consumed={...f.plan.consumed,'other.md':'x'};}],
['technical file',f=>{f.after['src/evil.php']={mode:'100644',bytes:Buffer.from('evil')};}],
['wrong output',f=>{f.after['CHANGELOG.md'].bytes=Buffer.from('other');}],
['minor version',f=>{f.plan.next_version='1.3.0';}],
['publication absent',f=>{f.publicationAuthorization=null;}],
['other repository authority',f=>{f.publicationAuthorization.repository='other/repo';}],
['other HEAD authority',f=>{f.publicationAuthorization.head='c'.repeat(40);}],
['history backfill',f=>{f.plan.historical_versions=['1.0.0'];}],
['recovery plan',f=>{f.plan.resuming=true;}],
])test(`release blocks ${name}`,()=>{const f=fixture();mutate(f);assert.throws(()=>proveManagedPatchRelease(f));});
test('compiler adapter invokes trusted runtime dry-run without consumer scripts or credentials',()=>{
 const calls=[];const result=compileVersionPlan({runtimeCli:'/trusted/bin/changelog',project:'/snapshot',base:'a'.repeat(40),repository:'fixture/repo'},(program,args,options)=>{
 calls.push({program,args,options});if(program==='git')return args[0]==='rev-parse'?'a'.repeat(40)+'\n':'';return '{"id":"plan1"}';});
 assert.equal(result.id,'plan1');assert.ok(calls[2].args.includes('/trusted/bin/changelog'));assert.ok(calls[2].args.includes('--dry-run'));
 assert.equal(calls[2].options.env.GITHUB_TOKEN,undefined);assert.ok(calls[2].args.includes('--template=keep-a-changelog'));
});

test('actual packaged compiler plans a PATCH from a clean disposable base without mutation', async () => {
 const {mkdtempSync,mkdirSync,writeFileSync,rmSync,realpathSync}=await import('node:fs');
 const {tmpdir}=await import('node:os');const {join}=await import('node:path');const {execFileSync}=await import('node:child_process');
 const project=realpathSync(mkdtempSync(join(tmpdir(),'changelog-execution-plan-')));
 const git=(args)=>execFileSync('git',args,{cwd:project,encoding:'utf8',env:{PATH:process.env.PATH,HOME:project,TMPDIR:process.env.TMPDIR||'/tmp'}});
 try {
  git(['init','-q']);git(['config','user.name','Synthetic Fixture']);git(['config','user.email','fixture@example.test']);
  writeFileSync(join(project,'CHANGELOG.md'),'# Changelog\n\n## [1.2.3] - 2026-01-01\n\n### Fixed\n\n- Previous fix.\n');
  mkdirSync(join(project,'.changelog'));writeFileSync(join(project,'.changelog','patch.md'),'---\ncategory: fixed\ntype: patch\n---\n\nUpdates an authorized patch.\n');
  git(['add','.']);git(['-c','commit.gpgsign=false','commit','-qm','Synthetic base']);const base=git(['rev-parse','HEAD']).trim();
  const plan=compileVersionPlan({runtimeCli:new URL('../../bin/changelog',import.meta.url).pathname,project,base,repository:'fixture/repo'});
  assert.equal(plan.base_sha,base);assert.equal(plan.impact,'patch');assert.equal(plan.next_version,'1.2.4');
  assert.deepEqual(Object.keys(plan.consumed),['.changelog/patch.md']);assert.equal(git(['status','--porcelain']),'');
 } finally {rmSync(project,{recursive:true,force:true});}
});


test('managed runtime inspector rejects false from the existing ownership validator',async()=>{
 const {managedVersionInspector}=await import('../../.github/scripts/dependabot-release-proof.mjs');
 const inspect=managedVersionInspector({requireManagedOwnership:async()=>false,compiler:()=>assert.fail('must not compile')});
 await assert.rejects(inspect({}),/ownership inspection rejected/);
});

test('ownership adapter calls real managed policy contract with writes disabled',async()=>{
 const {managedOwnershipInspector}=await import('../../.github/scripts/dependabot-release-proof.mjs');
 const candidate={repository:'fixture/repo',pull_request:7,head:'a'.repeat(40),base:'b'.repeat(40)};const calls=[];
 const inspect=managedOwnershipInspector({runtimeCli:'/trusted/bin/changelog',headSnapshot:'/snapshot',managedBranch:'changelog/version',actor:'release[bot]',baseBranch:'main',readToken:'synthetic-token'},(program,args,options)=>{
 calls.push({program,args,options});if(program==='git')return args[0]==='rev-parse'?candidate.head+'\n':'';
 return JSON.stringify({status:'valid',kind:'managed-version',head_sha:candidate.head,base_sha:candidate.base,pull_request:7});});
 assert.equal(await inspect(candidate),true);assert.ok(calls[2].args.includes('--publish-status=false'));
 assert.equal(calls[2].args.includes('--dry-run=true'),false);assert.equal(calls[2].args.includes('synthetic-token'),false);
});
