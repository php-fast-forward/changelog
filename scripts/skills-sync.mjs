/**
 * Generates repository skill adapters from the canonical portable package.
 * Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * SPDX-License-Identifier: MIT
 */
import { lstat, mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import { dirname, join, parse, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const sourcePath = '.agents/skills/changelog';
const adapterPaths = ['.claude/skills/changelog', '.github/skills/changelog'];

/** Refuses symbolic ancestors before reading or writing any declared package path. */
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

/** Returns bounded regular resources and rejects symbolic, special or excessive trees. */
async function inventory(root, optional = false) {
  await guard(root);
  try {
    if (!(await lstat(root)).isDirectory()) throw new Error(`Package directory required: ${root}`);
  } catch (error) {
    if (optional && error.code === 'ENOENT') return [];
    throw error;
  }
  const files = [];
  async function walk(relative = '', depth = 0) {
    if (depth > 8) throw new Error('Skill resources exceed maximum depth.');
    const entries = await readdir(join(root, relative), { withFileTypes: true });
    for (const entry of entries.sort((a, b) => a.name.localeCompare(b.name))) {
      const name = relative ? `${relative}/${entry.name}` : entry.name;
      const path = join(root, name);
      await guard(path);
      const state = await lstat(path);
      if (state.isDirectory()) await walk(name, depth + 1);
      else if (state.isFile()) {
        files.push(name);
        if (files.length > 200) throw new Error('Skill resources exceed maximum file count.');
      } else throw new Error(`Regular resource required: ${path}`);
    }
  }
  await walk();
  return files;
}

/** Preflights both generated adapters before any mutation and preserves unknown files. */
export async function synchronize(repositoryRoot, write = false) {
  const root = resolve(repositoryRoot);
  const source = join(root, sourcePath);
  const files = await inventory(source);
  if (!files.includes('SKILL.md') || !files.includes('LICENSE')) throw new Error('Canonical SKILL.md and LICENSE are required.');
  const bytes = new Map(await Promise.all(files.map(async (file) => [file, await readFile(join(source, file))])));
  const changes = [];
  for (const relative of adapterPaths) {
    const destination = join(root, relative);
    const existing = await inventory(destination, true);
    const unknown = existing.filter((file) => !files.includes(file));
    if (unknown.length) throw new Error(`Unknown generated-adapter resources preserved: ${relative}/${unknown.join(', ')}`);
    for (const file of files) {
      const target = join(destination, file);
      await guard(target);
      let current = null;
      try { current = await readFile(target); } catch (error) { if (error.code !== 'ENOENT') throw error; }
      if (current === null || !current.equals(bytes.get(file))) changes.push({ path: `${relative}/${file}`, target, file });
    }
  }
  if (write) {
    for (const change of changes) {
      await guard(change.target);
      await mkdir(dirname(change.target), { recursive: true });
      await guard(change.target);
      await writeFile(change.target, bytes.get(change.file));
    }
  }
  return { mode: write ? 'write' : 'check', files: files.length, changes: changes.map(({ path }) => path) };
}

/** CLI defaults to no-write verification; errors remain bounded diagnostics on stderr. */
async function main() {
  const args = process.argv.slice(2);
  if (args.length === 1 && args[0] === '--help') {
    console.log('Usage: node scripts/skills-sync.mjs [--check | --write]\nDefault --check is read-only; --write regenerates declared Claude/Copilot package copies.');
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
