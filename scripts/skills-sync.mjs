/**
 * Verifies one real repository skill package and its bounded host-directory links.
 * Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * SPDX-License-Identifier: MIT
 */
import { lstat, mkdir, readFile, readdir, readlink, rmdir, symlink, unlink } from 'node:fs/promises';
import { dirname, join, parse, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const sourcePath = '.github/skills/changelog';
const adapterPaths = ['.agents/skills/changelog', '.claude/skills/changelog'];
const linkTarget = '../../.github/skills/changelog';

/** Refuses symbolic ancestry; adapters themselves are examined without following them. */
async function guard(path) {
  for (let current = resolve(path); ; current = dirname(current)) {
    try {
      if ((await lstat(current)).isSymbolicLink()) throw new Error(`Symbolic path refused: ${current}`);
    } catch (error) {
      if (error.code !== 'ENOENT') throw error;
    }
    if (current === parse(current).root) break;
  }
}

/** Returns bounded regular resources; symbolic, special and excessive trees fail before any write. */
async function inventory(root) {
  await guard(root);
  if (!(await lstat(root)).isDirectory()) throw new Error(`Package directory required: ${root}`);
  const files = [];
  const directories = [];
  async function walk(relative = '', depth = 0) {
    if (depth > 8) throw new Error('Skill resources exceed maximum depth.');
    for (const entry of (await readdir(join(root, relative))).sort()) {
      const name = relative ? `${relative}/${entry}` : entry;
      const path = join(root, name);
      await guard(path);
      const state = await lstat(path);
      if (state.isDirectory()) {
        directories.push(name);
        await walk(name, depth + 1);
      } else if (state.isFile()) files.push(name);
      else throw new Error(`Regular resource required: ${path}`);
      if (files.length + directories.length > 200) throw new Error('Skill resources exceed maximum resource count.');
    }
  }
  await walk();
  return { files, directories };
}

/** Corroborates a Windows checkout's exact link-text file with one tracked 120000 index blob. */
function trackedLink(root, relative) {
  const options = { cwd: root, encoding: 'utf8', maxBuffer: 8192, windowsHide: true, env: {
    PATH: process.env.PATH, SystemRoot: process.env.SystemRoot, HOME: root, XDG_CONFIG_HOME: root,
    GIT_CONFIG_NOSYSTEM: '1', GIT_CONFIG_GLOBAL: process.platform === 'win32' ? 'NUL' : '/dev/null',
  } };
  const index = spawnSync('git', ['ls-files', '--stage', '--', relative], options);
  if (index.error || index.status !== 0) return false;
  const match = /^120000 ([a-f0-9]{40}|[a-f0-9]{64}) 0\t([^\r\n]+)\r?\n$/.exec(index.stdout);
  if (!match || match[2] !== relative) return false;
  const blob = spawnSync('git', ['cat-file', 'blob', match[1]], options);
  return !blob.error && blob.status === 0 && blob.stdout === linkTarget;
}

/** Preflights every destination and preserves manual resources before changing either declared host link. */
export async function synchronize(repositoryRoot, write = false, { platform = process.platform } = {}) {
  const root = resolve(repositoryRoot);
  const source = join(root, sourcePath);
  const canonical = await inventory(source);
  if (!canonical.files.includes('SKILL.md') || !canonical.files.includes('LICENSE')) throw new Error('Canonical SKILL.md and LICENSE are required.');
  const bytes = new Map(await Promise.all(canonical.files.map(async (file) => [file, await readFile(join(source, file))])));
  const changes = [];
  const links = [];
  for (const relative of adapterPaths) {
    const destination = join(root, relative);
    await guard(dirname(destination));
    let state = null;
    try { state = await lstat(destination); } catch (error) { if (error.code !== 'ENOENT') throw error; }
    if (state?.isSymbolicLink()) {
      const observed = await readlink(destination);
      const normalized = process.platform === 'win32' ? observed.replaceAll('\\', '/') : observed;
      if (normalized !== linkTarget || resolve(dirname(destination), linkTarget) !== source) throw new Error(`Unexpected adapter link preserved: ${relative}`);
      links.push({ path: relative, target: linkTarget, materialized: true });
      continue;
    }
    if (state?.isFile()) {
      if (platform === 'win32' && (await readFile(destination, 'utf8')) === linkTarget && trackedLink(root, relative)) {
        links.push({ path: relative, target: linkTarget, materialized: false, state: 'tracked-link-text' });
        continue;
      }
      throw new Error(`Unexpected adapter file preserved: ${relative}`);
    }
    let legacy = null;
    if (state !== null) {
      if (!state.isDirectory()) throw new Error(`Package directory or exact link required: ${relative}`);
      legacy = await inventory(destination);
      if (JSON.stringify(legacy) !== JSON.stringify(canonical)) throw new Error(`Unknown adapter resources preserved: ${relative}`);
      for (const file of legacy.files) {
        if (!(await readFile(join(destination, file))).equals(bytes.get(file))) throw new Error(`Divergent adapter resource preserved: ${relative}/${file}`);
      }
    }
    changes.push({ path: relative, destination, legacy });
    links.push({ path: relative, target: linkTarget, materialized: write });
  }
  if (write) {
    for (const change of changes) {
      await guard(dirname(change.destination));
      if (change.legacy) {
        // Recheck the exact legacy snapshot before unlinking only its declared regular resources.
        const current = await inventory(change.destination);
        if (JSON.stringify(current) !== JSON.stringify(change.legacy)) throw new Error(`Adapter changed during migration: ${change.path}`);
        for (const file of current.files) {
          if (!(await readFile(join(change.destination, file))).equals(bytes.get(file))) throw new Error(`Adapter changed during migration: ${change.path}/${file}`);
        }
        for (const file of current.files) { await guard(join(change.destination, file)); await unlink(join(change.destination, file)); }
        for (const directory of [...current.directories].sort((a, b) => b.length - a.length)) await rmdir(join(change.destination, directory));
        await rmdir(change.destination);
      }
      await mkdir(dirname(change.destination), { recursive: true });
      await guard(dirname(change.destination));
      await symlink(linkTarget, change.destination, 'dir');
    }
  }
  return { mode: write ? 'write' : 'check', source: sourcePath, files: canonical.files.length, changes: changes.map(({ path }) => path), links };
}

/** CLI defaults to read-only verification and reports unmaterialized tracked Windows links honestly. */
async function main() {
  const args = process.argv.slice(2);
  if (args.length === 1 && args[0] === '--help') {
    console.log('Usage: node scripts/skills-sync.mjs [--check | --write]\nDefault --check is read-only; --write creates exact Codex/Claude directory links to the real Copilot package.');
    return;
  }
  if (args.length > 1 || (args.length === 1 && !['--check', '--write'].includes(args[0]))) throw new Error('Use --check, --write or --help.');
  const result = await synchronize(resolve(dirname(fileURLToPath(import.meta.url)), '..'), args[0] === '--write');
  console.log(JSON.stringify(result));
  if (result.mode === 'check' && result.changes.length) process.exitCode = 1;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((error) => { console.error(`Skill synchronization failed: ${error.message}`); process.exitCode = 1; });
}
