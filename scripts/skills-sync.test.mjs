/** Disposable fixtures for the real canonical package and exact host links; no user state is used. */
import assert from 'node:assert/strict';
import { cp, lstat, mkdtemp, mkdir, readFile, readdir, readlink, realpath, rm, symlink, unlink, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { spawnSync } from 'node:child_process';
import { afterEach, test } from 'node:test';
import { synchronize } from './skills-sync.mjs';

const roots = [];
const temporaryDirectory = await realpath(tmpdir());
const canonical = '.github/skills/changelog';
const adapters = ['.agents/skills/changelog', '.claude/skills/changelog'];
const target = '../../.github/skills/changelog';

async function fixture() {
  const root = await mkdtemp(join(temporaryDirectory, 'changelog-skills-fixture-'));
  roots.push(root);
  await mkdir(join(root, canonical), { recursive: true });
  await writeFile(join(root, canonical, 'SKILL.md'), '---\nname: changelog\ndescription: fixture\n---\nFixture bytes.\n');
  await writeFile(join(root, canonical, 'LICENSE'), 'MIT fixture license\n');
  return root;
}

async function needsLinks(context, root) {
  try { await symlink(canonical, join(root, 'link-probe'), 'dir'); await unlink(join(root, 'link-probe')); return true; }
  catch (error) {
    if (process.platform === 'win32' && ['EPERM', 'EACCES', 'ENOTSUP'].includes(error.code)) {
      context.skip('Directory symlink creation is unavailable; tracked Windows link-text fixtures still run.');
      return false;
    }
    throw error;
  }
}

function git(root, args, input) {
  const result = spawnSync('git', args, { cwd: root, input, encoding: 'utf8', env: {
    PATH: process.env.PATH, SystemRoot: process.env.SystemRoot, HOME: root, XDG_CONFIG_HOME: root, GIT_CONFIG_NOSYSTEM: '1',
    GIT_CONFIG_COUNT: '1', GIT_CONFIG_KEY_0: 'core.symlinks', GIT_CONFIG_VALUE_0: 'false',
  } });
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.trim();
}

async function windowsLinks(root, mode = '120000') {
  git(root, ['init', '--quiet']);
  const oid = git(root, ['hash-object', '-w', '--stdin'], target);
  for (const adapter of adapters) {
    await mkdir(join(root, adapter, '..'), { recursive: true });
    await writeFile(join(root, adapter), target);
    git(root, ['update-index', '--add', '--cacheinfo', mode, oid, adapter]);
  }
}

afterEach(async () => { for (const root of roots.splice(0)) await rm(root, { recursive: true, force: true }); });

test('default check reports two absent links without creating adapters', async () => {
  const root = await fixture();
  const before = await readdir(root);
  const result = await synchronize(root);
  assert.equal(result.source, canonical);
  assert.equal(result.mode, 'check');
  assert.deepEqual(result.changes, adapters);
  assert.ok(result.links.every((link) => !link.materialized));
  assert.deepEqual(await readdir(root), before);
});

test('write creates exact directory symlinks to one real package and is idempotent', async (context) => {
  const root = await fixture();
  if (!await needsLinks(context, root)) return;
  const first = await synchronize(root, true);
  assert.deepEqual(first.changes, adapters);
  assert.equal((await lstat(join(root, canonical))).isDirectory(), true);
  assert.equal((await lstat(join(root, canonical))).isSymbolicLink(), false);
  for (const adapter of adapters) {
    assert.equal((await lstat(join(root, adapter))).isSymbolicLink(), true);
    assert.equal((await readlink(join(root, adapter))).replaceAll('\\', '/'), target);
    assert.equal(await realpath(join(root, adapter)), await realpath(join(root, canonical)));
    assert.deepEqual(await readFile(join(root, adapter, 'SKILL.md')), await readFile(join(root, canonical, 'SKILL.md')));
  }
  assert.deepEqual((await synchronize(root, true)).changes, []);
  assert.deepEqual((await synchronize(root)).changes, []);
});

test('canonical edits become visible through both links without copying or drift', async (context) => {
  const root = await fixture();
  if (!await needsLinks(context, root)) return;
  await synchronize(root, true);
  await writeFile(join(root, canonical, 'SKILL.md'), 'updated canonical bytes');
  for (const adapter of adapters) assert.equal(await readFile(join(root, adapter, 'SKILL.md'), 'utf8'), 'updated canonical bytes');
  assert.deepEqual((await synchronize(root)).changes, []);
});

test('legacy real copies migrate only when all resources and bytes match the canonical package', async (context) => {
  const root = await fixture();
  if (!await needsLinks(context, root)) return;
  await mkdir(join(root, canonical, 'references'));
  await writeFile(join(root, canonical, 'references/example.md'), Buffer.from([0, 1, 2, 255]));
  for (const adapter of adapters) await cp(join(root, canonical), join(root, adapter), { recursive: true });
  const check = await synchronize(root);
  assert.deepEqual(check.changes, adapters);
  assert.equal((await lstat(join(root, adapters[0]))).isDirectory(), true);
  assert.equal((await synchronize(root, true)).files, 3);
  assert.deepEqual(await readFile(join(root, adapters[1], 'references/example.md')), Buffer.from([0, 1, 2, 255]));
  assert.deepEqual((await synchronize(root)).changes, []);
});

test('unknown resources in the second legacy adapter preserve both copies before any migration', async () => {
  const root = await fixture();
  for (const adapter of adapters) await cp(join(root, canonical), join(root, adapter), { recursive: true });
  await writeFile(join(root, adapters[1], 'manual.txt'), 'unrelated bytes');
  await assert.rejects(synchronize(root, true), /Unknown adapter resources preserved/);
  assert.equal((await lstat(join(root, adapters[0]))).isDirectory(), true);
  assert.equal(await readFile(join(root, adapters[1], 'manual.txt'), 'utf8'), 'unrelated bytes');
});

test('divergent manual bytes at a known adapter path are preserved before any migration', async () => {
  const root = await fixture();
  for (const adapter of adapters) await cp(join(root, canonical), join(root, adapter), { recursive: true });
  await writeFile(join(root, adapters[1], 'SKILL.md'), 'manual procedure');
  await assert.rejects(synchronize(root, true), /Divergent adapter resource preserved/);
  assert.equal((await lstat(join(root, adapters[0]))).isDirectory(), true);
  assert.equal(await readFile(join(root, adapters[1], 'SKILL.md'), 'utf8'), 'manual procedure');
});

test('unknown empty adapter directories are preserved', async () => {
  const root = await fixture();
  await cp(join(root, canonical), join(root, adapters[0]), { recursive: true });
  await mkdir(join(root, adapters[0], 'manual-empty'));
  await assert.rejects(synchronize(root, true), /Unknown adapter resources preserved/);
  assert.equal((await lstat(join(root, adapters[0], 'manual-empty'))).isDirectory(), true);
});

for (const resource of ['SKILL.md', 'LICENSE']) {
  test(`missing canonical ${resource} fails without creating adapters`, async () => {
    const root = await fixture();
    await rm(join(root, canonical, resource));
    await assert.rejects(synchronize(root, true), /SKILL.md and LICENSE/);
    assert.deepEqual(await readdir(root), ['.github']);
  });
}

test('canonical package must be a real directory', async (context) => {
  const root = await fixture();
  if (!await needsLinks(context, root)) return;
  await cp(join(root, canonical), join(root, 'outside'), { recursive: true });
  await rm(join(root, canonical), { recursive: true });
  await symlink(join(root, 'outside'), join(root, canonical), 'dir');
  await assert.rejects(synchronize(root, true), /Symbolic path refused/);
  assert.equal(await readFile(join(root, 'outside/LICENSE'), 'utf8'), 'MIT fixture license\n');
});

test('canonical symbolic resources and symbolic adapter ancestors never reach external targets', async (context) => {
  const root = await fixture();
  if (!await needsLinks(context, root)) return;
  await mkdir(join(root, 'outside'));
  await symlink(join(root, 'outside'), join(root, canonical, 'linked'), 'dir');
  await assert.rejects(synchronize(root, true), /Symbolic path refused/);
  await unlink(join(root, canonical, 'linked'));
  await symlink(join(root, 'outside'), join(root, '.claude'), 'dir');
  await assert.rejects(synchronize(root, true), /Symbolic path refused/);
  assert.deepEqual(await readdir(join(root, 'outside')), []);
});

test('unexpected adapter link target is preserved without following it', async (context) => {
  const root = await fixture();
  if (!await needsLinks(context, root)) return;
  await mkdir(join(root, '.claude/skills'), { recursive: true });
  await symlink('/unrelated/unknown', join(root, adapters[1]), 'dir');
  await assert.rejects(synchronize(root, true), /Unexpected adapter link preserved/);
  assert.equal(await readlink(join(root, adapters[1])), '/unrelated/unknown');
  await assert.rejects(lstat(join(root, adapters[0])), { code: 'ENOENT' });
});

test('untracked regular link-text files do not manufacture Windows link evidence', async () => {
  const root = await fixture();
  await mkdir(join(root, '.agents/skills'), { recursive: true });
  await writeFile(join(root, adapters[0]), target);
  await assert.rejects(synchronize(root, false, { platform: 'win32' }), /Unexpected adapter file preserved/);
});

test('ordinary tracked blobs do not manufacture Windows link evidence', async () => {
  const root = await fixture();
  await windowsLinks(root, '100644');
  await assert.rejects(synchronize(root, false, { platform: 'win32' }), /Unexpected adapter file preserved/);
});

test('tracked Windows link-text fallback verifies exact 120000 blobs and reports unmaterialized links', async () => {
  const root = await fixture();
  await windowsLinks(root);
  const result = await synchronize(root, false, { platform: 'win32' });
  assert.deepEqual(result.changes, []);
  assert.ok(result.links.every((link) => link.state === 'tracked-link-text' && link.materialized === false));
  assert.deepEqual((await synchronize(root, true, { platform: 'win32' })).changes, []);
  assert.equal((await lstat(join(root, adapters[0]))).isFile(), true);
  await writeFile(join(root, adapters[1]), target + '\n');
  await assert.rejects(synchronize(root, true, { platform: 'win32' }), /Unexpected adapter file preserved/);
});

test('source depth and resource limits fail before adapter creation', async () => {
  const root = await fixture();
  await mkdir(join(root, canonical, ...Array(9).fill('deep')), { recursive: true });
  await assert.rejects(synchronize(root, true), /maximum depth/);
  await rm(join(root, canonical, 'deep'), { recursive: true });
  await Promise.all(Array.from({ length: 199 }, (_, index) => writeFile(join(root, canonical, `resource-${index}`), 'fixture')));
  await assert.rejects(synchronize(root, true), /maximum resource count/);
  assert.deepEqual(await readdir(root), ['.github']);
});

test('CLI help and invalid arguments have no writes; default check derives the root from script location', async (context) => {
  const root = await fixture();
  await mkdir(join(root, 'scripts'));
  await writeFile(join(root, 'scripts/skills-sync.mjs'), await readFile(new URL('./skills-sync.mjs', import.meta.url)));
  const script = join(root, 'scripts/skills-sync.mjs');
  const options = { cwd: temporaryDirectory, encoding: 'utf8', env: {} };
  assert.equal(spawnSync(process.execPath, [script, '--help'], options).status, 0);
  for (const args of [['--unknown'], ['--check', '--write']]) {
    const result = spawnSync(process.execPath, [script, ...args], options);
    assert.equal(result.status, 1);
    assert.match(result.stderr, /Use --check, --write or --help/);
  }
  const checked = spawnSync(process.execPath, [script], options);
  assert.equal(checked.status, 1);
  assert.equal(JSON.parse(checked.stdout).mode, 'check');
  assert.deepEqual(await readdir(root), ['.github', 'scripts']);
  if (!await needsLinks(context, root)) return;
  assert.equal(spawnSync(process.execPath, [script, '--write'], options).status, 0);
  assert.equal(spawnSync(process.execPath, [script, '--check'], options).status, 0);
});
