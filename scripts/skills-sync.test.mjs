/** Disposable integration fixtures for generated skill adapters; no host state is used. */
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, readdir, realpath, rm, symlink, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { spawnSync } from 'node:child_process';
import { afterEach, test } from 'node:test';
import { synchronize } from './skills-sync.mjs';

const roots = [];
// Platform aliases such as macOS /var must not trip the production symbolic-path guard.
const temporaryDirectory = await realpath(tmpdir());
const canonical = '.agents/skills/changelog';
const adapters = ['.claude/skills/changelog', '.github/skills/changelog'];

async function fixture() {
  const root = await mkdtemp(join(temporaryDirectory, 'changelog-skills-fixture-'));
  roots.push(root);
  await mkdir(join(root, canonical), { recursive: true });
  await writeFile(join(root, canonical, 'SKILL.md'), '---\nname: changelog\ndescription: fixture\n---\nFixture bytes.\n');
  await writeFile(join(root, canonical, 'LICENSE'), 'MIT fixture license\n');
  return root;
}

afterEach(async () => { for (const root of roots.splice(0)) await rm(root, { recursive: true, force: true }); });

test('default check reports drift without creating any adapters', async () => {
  const root = await fixture();
  const before = await readdir(root);
  const result = await synchronize(root);
  assert.equal(result.mode, 'check');
  assert.equal(result.changes.length, 4);
  assert.deepEqual(await readdir(root), before);
});

test('write creates real byte-identical copies and repeated writes/checks are empty', async () => {
  const root = await fixture();
  const first = await synchronize(root, true);
  assert.equal(first.changes.length, 4);
  for (const adapter of adapters) {
    assert.deepEqual(await readFile(join(root, adapter, 'SKILL.md')), await readFile(join(root, canonical, 'SKILL.md')));
    assert.deepEqual(await readFile(join(root, adapter, 'LICENSE')), await readFile(join(root, canonical, 'LICENSE')));
  }
  assert.deepEqual((await synchronize(root, true)).changes, []);
  assert.deepEqual((await synchronize(root)).changes, []);
});

test('changed source stays pending during check and write refreshes exactly two files', async () => {
  const root = await fixture();
  await synchronize(root, true);
  const previous = await readFile(join(root, adapters[0], 'SKILL.md'));
  await writeFile(join(root, canonical, 'SKILL.md'), 'changed source');
  assert.equal((await synchronize(root)).changes.length, 2);
  assert.deepEqual(await readFile(join(root, adapters[0], 'SKILL.md')), previous);
  assert.equal((await synchronize(root, true)).changes.length, 2);
  assert.deepEqual((await synchronize(root)).changes, []);
});

test('nested bundled resources are copied without changing their bytes', async () => {
  const root = await fixture();
  await mkdir(join(root, canonical, 'references'));
  await writeFile(join(root, canonical, 'references/example.md'), Buffer.from([0, 1, 2, 255]));
  assert.equal((await synchronize(root, true)).files, 3);
  assert.deepEqual(await readFile(join(root, adapters[1], 'references/example.md')), Buffer.from([0, 1, 2, 255]));
});

test('unknown second-adapter resources fail before first-adapter writes and are retained', async () => {
  const root = await fixture();
  await synchronize(root, true);
  const previous = await readFile(join(root, adapters[0], 'SKILL.md'));
  await writeFile(join(root, canonical, 'SKILL.md'), 'updated source');
  await writeFile(join(root, adapters[1], 'manual.txt'), 'unrelated bytes');
  await assert.rejects(synchronize(root, true), /Unknown generated-adapter resources/);
  assert.deepEqual(await readFile(join(root, adapters[0], 'SKILL.md')), previous);
  assert.equal(await readFile(join(root, adapters[1], 'manual.txt'), 'utf8'), 'unrelated bytes');
});

for (const resource of ['SKILL.md', 'LICENSE']) {
  test(`missing canonical ${resource} fails without creating adapters`, async () => {
    const root = await fixture();
    await rm(join(root, canonical, resource));
    await assert.rejects(synchronize(root, true), /SKILL.md and LICENSE/);
    assert.deepEqual(await readdir(root), ['.agents']);
  });
}

test('missing canonical package is rejected', async () => {
  const root = await fixture();
  await rm(join(root, canonical), { recursive: true });
  await assert.rejects(synchronize(root, true), /ENOENT/);
});

test('canonical symbolic resource is refused before generated writes', async () => {
  const root = await fixture();
  await writeFile(join(root, 'outside.md'), 'outside untouched');
  await symlink(join(root, 'outside.md'), join(root, canonical, 'linked.md'));
  await assert.rejects(synchronize(root, true), /Symbolic path refused/);
  assert.equal(await readFile(join(root, 'outside.md'), 'utf8'), 'outside untouched');
  assert.deepEqual(await readdir(root), ['.agents', 'outside.md']);
});

test('symbolic adapter ancestor is refused without writing its target', async () => {
  const root = await fixture();
  await mkdir(join(root, 'outside'));
  await symlink(join(root, 'outside'), join(root, '.claude'));
  await assert.rejects(synchronize(root, true), /Symbolic path refused/);
  assert.deepEqual(await readdir(join(root, 'outside')), []);
});

test('symbolic repository root is refused', async () => {
  const root = await fixture();
  await symlink(root, join(root, 'alias'));
  await assert.rejects(synchronize(join(root, 'alias'), true), /Symbolic path refused/);
});

test('non-directory adapter is rejected without writes', async () => {
  const root = await fixture();
  await mkdir(join(root, '.claude/skills'), { recursive: true });
  await writeFile(join(root, adapters[0]), 'unrelated file');
  await assert.rejects(synchronize(root, true), /Package directory required/);
  assert.equal(await readFile(join(root, adapters[0]), 'utf8'), 'unrelated file');
});

test('source resource limit fails before any adapter creation', async () => {
  const root = await fixture();
  await Promise.all(Array.from({ length: 199 }, (_, index) => writeFile(join(root, canonical, `resource-${index}`), 'fixture')));
  await assert.rejects(synchronize(root, true), /maximum file count/);
  assert.deepEqual(await readdir(root), ['.agents']);
});

test('source depth limit fails before any adapter creation', async () => {
  const root = await fixture();
  await mkdir(join(root, canonical, ...Array(9).fill('deep')), { recursive: true });
  await assert.rejects(synchronize(root, true), /maximum depth/);
  assert.deepEqual(await readdir(root), ['.agents']);
});

test('CLI help and invalid arguments are bounded and have no write effects', async () => {
  const root = await fixture();
  await mkdir(join(root, 'scripts'));
  await writeFile(join(root, 'scripts/skills-sync.mjs'), await readFile(new URL('./skills-sync.mjs', import.meta.url)));
  const script = join(root, 'scripts/skills-sync.mjs');
  const help = spawnSync(process.execPath, [script, '--help'], { cwd: root, encoding: 'utf8', env: {} });
  assert.equal(help.status, 0);
  assert.match(help.stdout, /Usage:/);
  for (const args of [['--unknown'], ['--check', '--write']]) {
    const result = spawnSync(process.execPath, [script, ...args], { cwd: root, encoding: 'utf8', env: {} });
    assert.equal(result.status, 1);
    assert.equal(result.stdout, '');
    assert.match(result.stderr, /Use --check, --write or --help/);
  }
  assert.deepEqual(await readdir(root), ['.agents', 'scripts']);
});

test('CLI derives its root from the copied script and default check never writes', async () => {
  const root = await fixture();
  await mkdir(join(root, 'scripts'));
  await writeFile(join(root, 'scripts/skills-sync.mjs'), await readFile(new URL('./skills-sync.mjs', import.meta.url)));
  const script = join(root, 'scripts/skills-sync.mjs');
  const checked = spawnSync(process.execPath, [script], { cwd: temporaryDirectory, encoding: 'utf8', env: {} });
  assert.equal(checked.status, 1);
  assert.equal(JSON.parse(checked.stdout).mode, 'check');
  assert.deepEqual(await readdir(root), ['.agents', 'scripts']);
  assert.equal(spawnSync(process.execPath, [script, '--write'], { cwd: temporaryDirectory, encoding: 'utf8', env: {} }).status, 0);
  assert.equal(spawnSync(process.execPath, [script, '--check'], { cwd: temporaryDirectory, encoding: 'utf8', env: {} }).status, 0);
});
