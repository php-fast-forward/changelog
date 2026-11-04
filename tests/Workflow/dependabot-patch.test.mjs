import test from 'node:test';
import assert from 'node:assert/strict';
import { evaluate, proveComposer, proveNpm, parseJson } from '../../.github/scripts/dependabot-patch.mjs';

const sha = 'a'.repeat(40); const head = 'b'.repeat(40);
const packageData = (name, version, reference) => ({ name, version, require: { php: '^8.2' },
  source: { type: 'git', url: `https://github.com/example/${name}.git`, reference },
  dist: { type: 'zip', url: `https://api.github.com/repos/example/${name}/zipball/${reference}`, reference, shasum: '' } });
const fixture = () => ({
  before: { manifest: { require: { 'vendor/pkg': '^1.2.3' } }, lock: {
    'content-hash': 'old', packages: [packageData('vendor/pkg', 'v1.2.3', sha)], 'packages-dev': [] } },
  after: { manifest: { require: { 'vendor/pkg': '^1.2.3' } }, lock: {
    'content-hash': 'new', packages: [packageData('vendor/pkg', 'v1.2.4', head)], 'packages-dev': [] } },
});

test('proves a direct lock-only patch and matching manifest patch', () => {
  const { before, after } = fixture();
  assert.equal(proveComposer(before, after)[0].to, 'v1.2.4');
  after.manifest.require['vendor/pkg'] = '^1.2.4';
  assert.equal(proveComposer(before, after).length, 1);
});

for (const [name, mutate] of [
  ['minor', (f) => { f.after.lock.packages[0].version = '1.3.0'; }],
  ['major', (f) => { f.after.lock.packages[0].version = '2.0.0'; }],
  ['downgrade', (f) => { f.after.lock.packages[0].version = '1.2.2'; }],
  ['unchanged version reference', (f) => { f.after.lock.packages[0].version = 'v1.2.3'; }],
  ['prerelease', (f) => { f.after.lock.packages[0].version = '1.2.4-rc.1'; }],
  ['build metadata', (f) => { f.after.lock.packages[0].version = '1.2.4+build'; }],
  ['ambiguous tag', (f) => { f.after.lock.packages[0].version = 'v1'; }],
  ['no change', (f) => { f.after = structuredClone(f.before); }],
  ['transitive', (f) => { f.before.manifest.require = {}; f.after.manifest.require = {}; }],
  ['ambiguous range', (f) => { f.after.manifest.require['vendor/pkg'] = '>=1.2'; }],
  ['exact unsatisfied', (f) => { for (const side of ['before', 'after']) f[side].manifest.require['vendor/pkg'] = '1.2.3'; }],
  ['zero caret unsatisfied', (f) => { for (const side of ['before', 'after']) {
    f[side].manifest.require['vendor/pkg'] = '^0.0.3'; f[side].lock.packages[0].version = side === 'before' ? '0.0.3' : '0.0.4'; } }],
  ['manifest mismatch', (f) => { f.after.manifest.require['vendor/pkg'] = '^1.2.5'; }],
  ['manifest script', (f) => { f.after.manifest.scripts = { install: 'evil' }; }],
  ['package script', (f) => { f.after.lock.packages[0].scripts = { install: 'evil' }; }],
  ['changed dependency graph', (f) => { f.after.lock.packages[0].require.php = '^8.5'; }],
  ['dist origin', (f) => { f.after.lock.packages[0].dist.url = 'https://evil.test/archive'; }],
  ['source origin', (f) => { f.after.lock.packages[0].source.url = 'https://evil.test/repo'; }],
  ['mutable reference', (f) => { f.after.lock.packages[0].source.reference = 'main'; }],
  ['missing source', (f) => { delete f.after.lock.packages[0].source; }],
  ['added package', (f) => { f.after.lock.packages.push(packageData('vendor/new', '1.0.0', sha)); }],
  ['mixed group', (f) => { for (const side of ['before', 'after']) {
    f[side].manifest.require['vendor/other'] = '^2.0.0';
    f[side].lock.packages.push(packageData('vendor/other', side === 'before' ? '2.0.0' : '2.1.0', sha)); } }],
  ['unknown lock metadata', (f) => { f.after.lock['plugin-api-version'] = '99'; }],
]) test(`blocks ${name}`, () => { const f = fixture(); mutate(f); assert.throws(() => proveComposer(f.before, f.after)); });

function sdkFixture() {
  const f = fixture(); const requests = [];
  const pr = { number: 7, state: 'open', draft: false, merged: false, changed_files: 1,
    user: { login: 'dependabot[bot]', id: 49699333, type: 'Bot' },
    head: { sha: head, repo: { full_name: 'fixture/consumer' } }, base: { sha, repo: { full_name: 'fixture/consumer' } } };
  const context = { repo: { owner: 'fixture', repo: 'consumer' }, eventName: 'pull_request',
    payload: { repository: { full_name: 'fixture/consumer' }, pull_request: structuredClone(pr) } };
  const checks = [{ name: 'review', app_id: 1 }, { name: 'security', app_id: 2 }];
  const runs = checks.map((check) => ({ name: check.name, app: { id: check.app_id }, head_sha: head,
    status: 'completed', conclusion: 'success' }));
  const files = [{ filename: 'composer.lock', status: 'modified' }];
  const tree = { truncated: false, tree: ['composer.json', 'composer.lock'].map((path) => ({ path, type: 'blob', mode: '100644' })) };
  const rest = {
    pulls: { get: async (request) => { requests.push(request); return { data: structuredClone(pr) }; }, listFiles: async () => files },
    repos: { getContent: async (request) => {
      requests.push(request); const side = request.ref === sha ? f.before : f.after;
      const content = JSON.stringify(request.path === 'composer.json' ? side.manifest : side.lock);
      return { data: { type: 'file', encoding: 'base64', size: Buffer.byteLength(content), content: Buffer.from(content).toString('base64') } };
    } },
    git: { getCommit: async (request) => ({ data: { tree: { sha: request.commit_sha } } }),
      getTree: async (request) => ({ data: { ...tree, tree: tree.tree.map((entry) => ({ ...entry,
        sha: entry.path.includes('lock') ? request.tree_sha : 'c'.repeat(40) })) } }) },
    checks: { listForRef: async () => runs },
  };
  return { f, pr, context, checks, runs, files, tree, requests, github: { rest, paginate: async (fn, request) => fn(request) } };
}
const run = (f, extra = {}) => evaluate({ ...f, optedIn: true, mode: 'evaluate', ecosystem: 'composer', ...extra });
test('actual adapter binds every read to immutable data and never grants authority', async () => {
  const f = sdkFixture(); const report = await run(f);
  assert.equal(report.patch_candidate, true); assert.equal(report.merge_authorized, false);
  assert.equal(report.execution, 'unavailable'); assert.equal(report.base, sha); assert.equal(report.head, head);
  assert.equal(f.requests.filter((request) => request.ref === head || request.ref === sha).length, 4);
});
for (const [name, mutate, options] of [
  ['opt-out', () => {}, { optedIn: false }],
  ['execution', () => {}, { mode: 'execute' }],
  ['privileged event', (f) => { f.context.eventName = 'pull_request_target'; }],
  ['fake login', (f) => { f.pr.user.login = 'fake[bot]'; }],
  ['fake bot id', (f) => { f.pr.user.id = 1; }],
  ['fake type', (f) => { f.pr.user.type = 'User'; }],
  ['stale head', (f) => { f.pr.head.sha = sha; }],
  ['stale base', (f) => { f.pr.base.sha = head; }],
  ['fork', (f) => { f.pr.head.repo.full_name = 'fork/consumer'; }],
  ['draft', (f) => { f.pr.draft = true; }],
  ['unrelated code', (f) => { f.files[0].filename = 'src/evil.php'; }],
  ['incomplete diff', (f) => { f.pr.changed_files = 2; }],
  ['renamed lock', (f) => { f.files[0].status = 'renamed'; }],
  ['symlink', (f) => { f.tree.tree[0].mode = '120000'; }],
  ['truncated tree', (f) => { f.tree.truncated = true; }],
  ['missing security', (f) => { f.runs.pop(); }],
  ['spoofed check', (f) => { f.runs[0].app.id = 99; }],
  ['wrong check head', (f) => { f.runs[0].head_sha = sha; }],
  ['skipped check', (f) => { f.runs[0].conclusion = 'skipped'; }],
  ['pending check', (f) => { f.runs[0].status = 'in_progress'; }],
  ['duplicate check', (f) => { f.runs.push(structuredClone(f.runs[0])); }],
  ['no check policy', () => {}, { checks: [] }],
  ['racing head', (f) => { let reads = 0; f.github.rest.pulls.get = async () => {
    if (++reads === 2) f.pr.head.sha = sha; return { data: structuredClone(f.pr) }; }; }],
  ['API failure', (f) => { f.github.rest.repos.getContent = async () => { throw Error('offline'); }; }],
]) test(`adapter blocks ${name}`, async () => { const f = sdkFixture(); mutate(f); await assert.rejects(run(f, options)); });

test('all-patch groups are independently proved', () => {
  const f = fixture();
  for (const side of ['before', 'after']) {
    f[side].manifest.require['vendor/other'] = '^2.0.0';
    f[side].lock.packages.push(packageData('vendor/other', side === 'before' ? '2.0.0' : '2.0.1', sha));
  }
  assert.equal(proveComposer(f.before, f.after).length, 2);
});

test('actual workflow script invokes the tested adapter and publishes evidence only', { skip: !process.env.FF_PATCH_SCRIPT }, async () => {
  const f = sdkFixture(); const outputs = {};
  const oldEnv = { ...process.env };
  Object.assign(process.env, { GITHUB_WORKSPACE: new URL('../..', import.meta.url).pathname,
    FF_ECOSYSTEM: 'composer', FF_DIRECTORY: '.', FF_OPT_IN: 'true', FF_MODE: 'evaluate', FF_CHECKS: JSON.stringify(f.checks) });
  const summary = { addHeading() { return this; }, addCodeBlock() { return this; }, async write() {} };
  try {
    const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
    await new AsyncFunction('github', 'context', 'core', process.env.FF_PATCH_SCRIPT)(f.github, f.context,
      { setOutput: (key, value) => { outputs[key] = JSON.parse(value); }, summary });
    assert.equal(outputs.report.patch_candidate, true);
    assert.equal(outputs.report.merge_authorized, false);
  } finally { process.env = oldEnv; }
});

function npmFixture() {
  const make = (version) => ({ manifest: { name: 'pilot', version: '1.0.0', private: true,
    dependencies: { example: '^1.2.3' }, scripts: { test: 'node --test' } },
  lock: { name: 'pilot', version: '1.0.0', lockfileVersion: 3, requires: true, packages: {
    '': { name: 'pilot', version: '1.0.0', dependencies: { example: '^1.2.3' } },
    'node_modules/example': { version, resolved: `https://registry.npmjs.org/example/-/example-${version}.tgz`,
      integrity: 'sha512-' + 'a'.repeat(86) + '==', license: 'MIT' },
  } } });
  return { before: make('1.2.3'), after: make('1.2.4') };
}
test('npm proves direct lock-only patch and matching manifest/lock transition', () => {
  const f = npmFixture(); assert.equal(proveNpm(f.before, f.after).length, 1);
  f.after.manifest.dependencies.example = '^1.2.4';
  f.after.lock.packages[''].dependencies.example = '^1.2.4';
  assert.equal(proveNpm(f.before, f.after).length, 1);
});
for (const [name, mutate] of [
  ['mixed group', (f) => { for (const side of ['before', 'after']) {
    f[side].manifest.dependencies.other = '^2.0.0'; f[side].lock.packages[''].dependencies.other = '^2.0.0';
    f[side].lock.packages['node_modules/other'] = { ...f[side].lock.packages['node_modules/example'],
      version: side === 'before' ? '2.0.0' : '2.1.0' }; } }],
  ['prerelease', (f) => { f.after.lock.packages['node_modules/example'].version = '1.2.4-rc.1'; }],
  ['build', (f) => { f.after.lock.packages['node_modules/example'].version = '1.2.4+meta'; }],
  ['mutable tag', (f) => { f.after.lock.packages['node_modules/example'].version = 'latest'; }],
  ['transitive', (f) => { for (const side of ['before', 'after']) {
    f[side].manifest.dependencies = {}; f[side].lock.packages[''].dependencies = {}; } }],
  ['nested transitive', (f) => { for (const side of ['before', 'after']) {
    f[side].lock.packages['node_modules/other/node_modules/example'] = f[side].lock.packages['node_modules/example'];
    delete f[side].lock.packages['node_modules/example']; } }],
  ['changed graph', (f) => { f.after.lock.packages['node_modules/example'].dependencies = { other: '^1.0.0' }; }],
  ['install script', (f) => { f.after.lock.packages['node_modules/example'].hasInstallScript = true; }],
  ['changed manifest script', (f) => { f.after.manifest.scripts.test = 'evil'; }],
  ['foreign registry', (f) => { f.after.lock.packages['node_modules/example'].resolved = 'https://evil.test/example-1.2.4.tgz'; }],
  ['foreign package', (f) => { f.after.lock.packages['node_modules/example'].resolved = 'https://registry.npmjs.org/evil/-/evil-1.2.4.tgz'; }],
  ['weak integrity', (f) => { f.after.lock.packages['node_modules/example'].integrity = 'sha1-abc'; }],
  ['lock mismatch', (f) => { f.after.lock.packages[''].dependencies.example = '^1.2.4'; }],
  ['manifest mismatch', (f) => { f.after.manifest.dependencies.example = '^1.2.5'; f.after.lock.packages[''].dependencies.example = '^1.2.5'; }],
  ['ambiguous range', (f) => { for (const side of ['before', 'after']) {
    f[side].manifest.dependencies.example = '>=1.2'; f[side].lock.packages[''].dependencies.example = '>=1.2'; } }],
  ['v2 lock', (f) => { f.after.lock.lockfileVersion = 2; }],
  ['link', (f) => { f.after.lock.packages['node_modules/example'].link = true; }],
  ['no upgrade', (f) => { f.after = structuredClone(f.before); }],
]) test(`npm blocks ${name}`, () => { const f = npmFixture(); mutate(f); assert.throws(() => proveNpm(f.before, f.after)); });

test('npm adapter reads only the configured nested pilot data', async () => {
  const f = sdkFixture(); const npm = npmFixture();
  const directory = 'tests/fixtures/uptimeflare-runtime';
  f.files[0].filename = directory + '/package-lock.json';
  f.tree.tree = ['package.json', 'package-lock.json'].map((name) => ({ path: directory + '/' + name, type: 'blob', mode: '100644' }));
  f.github.rest.repos.getContent = async (request) => {
    assert.ok([sha, head].includes(request.ref));
    const side = request.ref === sha ? npm.before : npm.after;
    const content = JSON.stringify(request.path.endsWith('/package.json') ? side.manifest : side.lock);
    return { data: { type: 'file', encoding: 'base64', size: Buffer.byteLength(content), content: Buffer.from(content).toString('base64') } };
  };
  assert.equal((await run(f, { ecosystem: 'npm', directory })).patch_candidate, true);
  await assert.rejects(run(f, { ecosystem: 'npm', directory: '../evil' }));
});


test('JSON parser rejects nested and escaped duplicate keys without confusing string contents', () => {
  assert.deepEqual(parseJson('{"a":[true,null,{"x":"a\\\" : b"}]}'), { a: [true, null, { x: 'a" : b' }] });
  for (const text of ['{"a":1,"a":2}', '{"nested":{"a":1,"a":2}}', '{"a":1,"\\u0061":2}']) {
    assert.throws(() => parseJson(text));
  }
});


test('npm pilot shape supports exact scoped dependency patches with engines and scripts intact', () => {
  const f = npmFixture();
  for (const side of ['before', 'after']) {
    const version = side === 'before' ? '1.32.2' : '1.32.3';
    const manifest = { name: '@mentordosnerds/evolution-mcp', version: '0.3.0', private: true,
      type: 'module', engines: { node: '>=24' }, scripts: { start: 'node server.mjs', test: 'node --test' },
      dependencies: { '@modelcontextprotocol/sdk': version } };
    f[side].manifest = manifest;
    f[side].lock = { name: manifest.name, version: manifest.version, lockfileVersion: 3, requires: true, packages: {
      '': { name: manifest.name, version: manifest.version, engines: manifest.engines, dependencies: manifest.dependencies },
      'node_modules/@modelcontextprotocol/sdk': { version,
        resolved: `https://registry.npmjs.org/@modelcontextprotocol/sdk/-/sdk-${version}.tgz`,
        integrity: 'sha512-' + 'a'.repeat(86) + '==', license: 'MIT' },
    } };
  }
  assert.equal(proveNpm(f.before, f.after)[0].name, '@modelcontextprotocol/sdk');
});
for (const key of ['workspaces', 'overrides', 'optionalDependencies', 'peerDependencies']) {
  test(`npm blocks root ${key}`, () => {
    const f = npmFixture(); for (const side of ['before', 'after']) f[side].manifest[key] = {};
    assert.throws(() => proveNpm(f.before, f.after));
  });
}


test('JSON proof rejects numeric coercion that could hide a nondependency metadata change', () => {
  for (const token of ['9007199254740992', '9007199254740993', '1.00000000000000001', '1e0', '-0', '1e999']) {
    assert.throws(() => parseJson('{"limit":' + token + '}'));
  }
  assert.deepEqual(parseJson('{"limit":9007199254740991}'), { limit: Number.MAX_SAFE_INTEGER });
});
test('npm dependency constraints cannot be arrays or other coerced values', () => {
  const f = npmFixture();
  f.after.manifest.dependencies.example = ['1.2.4'];
  f.after.lock.packages[''].dependencies.example = ['1.2.4'];
  assert.throws(() => proveNpm(f.before, f.after));
});


test('immutable trees reject an ABA listFiles snapshot hiding unrelated code', async () => {
  const f = sdkFixture(); f.pr.changed_files = 2;
  f.files.push({ filename: 'composer.json', status: 'modified' });
  const getTree = f.github.rest.git.getTree;
  f.github.rest.git.getTree = async (request) => {
    const result = await getTree(request);
    if (request.tree_sha === head) result.data.tree.push({ path: 'src/evil.php', type: 'blob', mode: '100644', sha: head });
    return result;
  };
  await assert.rejects(run(f), /full immutable HEAD\/base delta/);
});
test('immutable trees reject unrelated file deletion and mode-only changes', async () => {
  for (const kind of ['deletion', 'mode']) {
    const f = sdkFixture(); const getTree = f.github.rest.git.getTree;
    f.github.rest.git.getTree = async (request) => {
      const result = await getTree(request);
      if (request.tree_sha === sha || kind === 'mode') result.data.tree.push({ path: 'src/existing.php', type: 'blob',
        mode: request.tree_sha === head ? '100755' : '100644', sha });
      return result;
    };
    await assert.rejects(run(f), /full immutable HEAD\/base delta/);
  }
});

for (const [name, mutate] of [
  ['base array lock version', (f) => { f.before.lock.packages[0].version = ['v1.2.3']; }],
  ['head array lock version', (f) => { f.after.lock.packages[0].version = ['v1.2.4']; }],
  ['base array manifest constraint', (f) => {
    f.before.manifest.require['vendor/pkg'] = ['^1.2.3']; f.after.manifest.require['vendor/pkg'] = '^1.2.4';
  }],
  ['head array manifest constraint', (f) => { f.after.manifest.require['vendor/pkg'] = ['^1.2.4']; }],
]) test(`Composer rejects ${name}`, () => {
  const f = fixture(); mutate(f); assert.throws(() => proveComposer(f.before, f.after));
});
for (const checksum of ['', 'arbitrary-checksum', 'a'.repeat(40)]) {
  test(`Composer rejects static distribution URL with checksum ${checksum || '(empty)'}`, () => {
    const f = fixture();
    for (const side of ['before', 'after']) Object.assign(f[side].lock.packages[0].dist, {
      url: 'https://repo.example/vendor/pkg.zip', shasum: checksum,
    });
    assert.throws(() => proveComposer(f.before, f.after));
  });
}

test('documented caller skips ordinary PRs and admits Dependabot for full evaluator identity checks', async () => {
  const { readFileSync } = await import('node:fs');
  const guide = readFileSync(new URL('../../docs/dependabot-patch.md', import.meta.url), 'utf8');
  const caller = guide.match(/```yaml\n([\s\S]*?)\n```/)[1];
  const condition = caller.match(/^    if: (.+)$/m)?.[1];
  assert.equal(condition, "github.event.pull_request.user.login == 'dependabot[bot]'");
  const jobRuns = new Function('github', `return (${condition});`);
  assert.equal(jobRuns({ event: { pull_request: { user: { login: 'contributor' } } } }), false);
  assert.equal(jobRuns({ event: { pull_request: { user: { login: 'dependabot[bot]' } } } }), true);
  const f = sdkFixture(); f.pr.user.id = 1;
  await assert.rejects(run(f), /official Dependabot identity/);
});
