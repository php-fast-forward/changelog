import { isDeepStrictEqual as equal } from 'node:util';

const assert = (condition, reason) => { if (!condition) throw new Error(reason); };
const stable = (version) => {
  assert(typeof version === 'string', 'Versions must be strings.');
  const match = /^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.exec(version);
  assert(match, 'Only complete stable SemVer versions are supported.');
  return match.slice(1).map(BigInt);
};
const patch = (before, after) => {
  const a = stable(before); const b = stable(after);
  assert(a[0] === b[0] && a[1] === b[1] && b[2] > a[2], 'Every version change must be a forward PATCH.');
};
const without = (object, keys) => Object.fromEntries(Object.entries(object).filter(([key]) => !keys.includes(key)));
const object = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

/** Parse valid JSON while rejecting duplicate keys even when their spellings are escaped. */
export function parseJson(text) {
  const result = JSON.parse(text);
  const tokens = text.match(/"(?:[^"\\]|\\.)*"|[{}\[\]:,]|[^{}\[\]:,\s]+/g) ?? [];
  let cursor = 0;
  const value = () => {
    const token = tokens[cursor++];
    if (token === '{') {
      const keys = new Set();
      if (tokens[cursor] !== '}') {
        do {
          const key = JSON.parse(tokens[cursor++]);
          assert(!keys.has(key), 'Duplicate JSON key.');
          keys.add(key);
          assert(tokens[cursor++] === ':', 'Invalid JSON key separator.');
          value();
        } while (tokens[cursor] === ',' && ++cursor);
      }
      assert(tokens[cursor++] === '}', 'Invalid JSON object.');
    } else if (token === '[') {
      if (tokens[cursor] !== ']') do { value(); } while (tokens[cursor] === ',' && ++cursor);
      assert(tokens[cursor++] === ']', 'Invalid JSON array.');
    } else if (/^-?\d/.test(token)) {
      assert(Number.isSafeInteger(Number(token)) && String(Number(token)) === token,
        'Noncanonical or unsafe JSON numeric values require manual review.');
    }
  };
  value();
  assert(cursor === tokens.length, 'Incomplete JSON tokens.');
  return result;
}

/** Prove the entire supported Composer delta; unknown ecosystems and metadata fail closed. */
export function proveComposer(before, after) {
  const changes = [];
  for (const side of [before, after]) {
    assert(object(side.manifest) && object(side.lock), 'Manifest and lock must be objects.');
    for (const section of ['require', 'require-dev']) {
      assert(object(side.manifest[section] ?? {})
        && Object.values(side.manifest[section] ?? {}).every((constraint) => typeof constraint === 'string'),
      'Composer dependency constraints must all be strings.');
    }
    for (const section of ['packages', 'packages-dev']) {
      assert(Array.isArray(side.lock[section]), 'Both lock package sections are required.');
    }
  }
  assert(equal(without(before.lock, ['content-hash', 'packages', 'packages-dev']),
    without(after.lock, ['content-hash', 'packages', 'packages-dev'])), 'Unknown lock metadata changed.');
  assert(equal(without(before.manifest, ['require', 'require-dev']),
    without(after.manifest, ['require', 'require-dev'])), 'Non-dependency manifest content changed.');
  const seen = new Set();
  for (const section of ['packages', 'packages-dev']) {
    const previous = before.lock[section]; const next = after.lock[section];
    assert(previous.length === next.length, 'Added or removed dependencies are unsupported.');
    for (let index = 0; index < previous.length; index++) {
      const a = previous[index]; const b = next[index];
      assert(object(a) && object(b) && typeof a.name === 'string' && a.name === b.name && !seen.has(a.name),
        'Package identity, order or uniqueness changed.');
      seen.add(a.name);
      if (equal(a, b)) continue;
      patch(a.version, b.version);
      assert(equal(without(a, ['version', 'source', 'dist', 'time']), without(b, ['version', 'source', 'dist', 'time'])),
        'Package requirements, scripts or unknown metadata changed.');
      for (const key of ['source', 'dist']) {
        assert(object(a[key]) && object(b[key]), 'Complete source and dist evidence is required.');
        assert(equal(without(a[key], ['reference', 'url']), without(b[key], ['reference', 'url'])),
          'Source or distribution metadata changed.');
        assert(typeof a[key].reference === 'string' && typeof b[key].reference === 'string'
          && /^[a-f0-9]{40}$/.test(a[key].reference) && /^[a-f0-9]{40}$/.test(b[key].reference),
        'Immutable source and distribution references are required.');
        if (key === 'source') assert(a[key].url === b[key].url, 'Source repository changed.');
        else {
          assert(typeof a[key].url === 'string' && typeof b[key].url === 'string', 'Distribution URL is missing.');
          assert(a[key].url.includes(a[key].reference) && b[key].url.includes(b[key].reference),
            'Each distribution URL must embed its own immutable reference; static URLs are unsupported.');
          assert(a[key].url.replace(a[key].reference, '{ref}') === b[key].url.replace(b[key].reference, '{ref}'),
            'Distribution origin or URL structure changed.');
        }
      }
      assert(a.source.reference === a.dist.reference && b.source.reference === b.dist.reference,
        'Source and distribution references disagree.');
      changes.push({ name: a.name, from: a.version, to: b.version, section });
    }
  }
  assert(changes.length > 0, 'No proven locked PATCH change.');
  for (const section of ['require', 'require-dev']) {
    const a = before.manifest[section] ?? {}; const b = after.manifest[section] ?? {};
    assert(object(a) && object(b) && equal(Object.keys(a).sort(), Object.keys(b).sort()),
      'Manifest dependency identities changed.');
    for (const name of Object.keys(a)) {
      if (a[name] === b[name]) continue;
      const oldConstraint = /^(\^|~|)(v?\d+\.\d+\.\d+)$/.exec(a[name]);
      const newConstraint = /^(\^|~|)(v?\d+\.\d+\.\d+)$/.exec(b[name]);
      assert(oldConstraint && newConstraint && oldConstraint[1] === newConstraint[1], 'Ambiguous manifest constraints.');
      patch(oldConstraint[2], newConstraint[2]);
      const change = changes.find((entry) => entry.name === name);
      assert(change && equal(stable(change.from), stable(oldConstraint[2]))
        && equal(stable(change.to), stable(newConstraint[2])), 'Manifest and locked version changes disagree.');
    }
  }
  for (const change of changes) {
    const section = change.section === 'packages' ? 'require' : 'require-dev';
    const constraint = after.manifest[section]?.[change.name];
    const match = typeof constraint === 'string' && /^(\^|~|)(v?\d+\.\d+\.\d+)$/.exec(constraint);
    assert(match, 'Transitive updates and ambiguous direct constraints require manual review.');
    const floor = stable(match[2]); const locked = stable(change.to);
    assert(floor[0] === locked[0] && floor[1] === locked[1] && locked[2] >= floor[2]
      && (match[1] !== '' || locked[2] === floor[2])
      && (match[1] !== '^' || floor[0] !== 0n || floor[1] !== 0n || locked[2] === floor[2]),
    'The changed lock must satisfy the supported direct manifest constraint.');
  }
  return changes;
}

/** Prove npm v3 lock changes for direct dependencies; graph/metadata changes block. */
export function proveNpm(before, after) {
  const changes = [];
  for (const side of [before, after]) {
    assert(object(side.manifest) && object(side.lock) && side.lock.lockfileVersion === 3
      && object(side.lock.packages) && object(side.lock.packages['']), 'npm lockfile v3 with a root package is required.');
    assert(!Object.hasOwn(side.manifest, 'workspaces') && !Object.hasOwn(side.manifest, 'overrides')
      && !Object.hasOwn(side.manifest, 'optionalDependencies') && !Object.hasOwn(side.manifest, 'peerDependencies'),
    'Workspaces, overrides and optional/peer root dependencies require manual review.');
    for (const section of ['dependencies', 'devDependencies']) {
      assert(object(side.manifest[section] ?? {})
        && Object.values(side.manifest[section] ?? {}).every((constraint) => typeof constraint === 'string'),
      'npm dependency constraints must all be strings.');
      assert(equal(side.manifest[section] ?? {}, side.lock.packages[''][section] ?? {}),
        'Root lock and manifest dependencies disagree.');
    }
    assert(Object.entries(without(side.lock.packages[''], ['dependencies', 'devDependencies']))
      .every(([key, value]) => Object.hasOwn(side.manifest, key) && equal(value, side.manifest[key])),
    'npm root lock metadata must agree with the manifest.');
  }
  assert(equal(without(before.manifest, ['dependencies', 'devDependencies']),
    without(after.manifest, ['dependencies', 'devDependencies'])), 'Non-dependency npm manifest content changed.');
  assert(equal(without(before.lock, ['packages']), without(after.lock, ['packages'])), 'Unknown npm lock metadata changed.');
  assert(equal(Object.keys(before.lock.packages).sort(), Object.keys(after.lock.packages).sort()),
    'Added or removed npm packages are unsupported.');
  assert(equal(without(before.lock.packages[''], ['dependencies', 'devDependencies']),
    without(after.lock.packages[''], ['dependencies', 'devDependencies'])), 'npm root lock metadata changed.');
  for (const path of Object.keys(before.lock.packages)) {
    if (path === '') continue;
    const a = before.lock.packages[path]; const b = after.lock.packages[path];
    assert(object(a) && object(b), 'Invalid npm package records.');
    if (equal(a, b)) continue;
    const match = /^node_modules\/((?:@[a-z0-9_.-]+\/)?[a-z0-9_.-]+)$/.exec(path);
    assert(match && !a.link && !b.link, 'Nested/transitive, linked or ambiguous npm dependency.');
    const name = match[1];
    const sections = ['dependencies', 'devDependencies'].filter((section) => Object.hasOwn(after.manifest[section] ?? {}, name));
    assert(sections.length === 1, 'Only unambiguous direct npm dependencies are supported.');
    patch(a.version, b.version);
    assert(equal(without(a, ['version', 'resolved', 'integrity']), without(b, ['version', 'resolved', 'integrity'])),
      'npm scripts, dependency graph or other package metadata changed.');
    for (const record of [a, b]) {
      assert(typeof record.version === 'string' && /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(record.version),
        'npm requires unambiguous stable numeric versions.');
      assert(record.resolved === `https://registry.npmjs.org/${name}/-/${name.split('/').at(-1)}-${record.version}.tgz`
        && typeof record.integrity === 'string' && /^sha512-[A-Za-z0-9+/]{86}==$/.test(record.integrity),
      'Only matching npm registry package tarballs with SHA-512 integrity are supported.');
    }
    const section = sections[0];
    assert(typeof before.manifest[section]?.[name] === 'string'
      && typeof after.manifest[section]?.[name] === 'string', 'npm dependency constraints must be strings.');
    const oldConstraint = /^(\^|~|)(\d+\.\d+\.\d+)$/.exec(before.manifest[section]?.[name]);
    const newConstraint = /^(\^|~|)(\d+\.\d+\.\d+)$/.exec(after.manifest[section]?.[name]);
    assert(oldConstraint && newConstraint && oldConstraint[1] === newConstraint[1], 'Ambiguous npm constraints.');
    if (oldConstraint[2] !== newConstraint[2]) {
      patch(oldConstraint[2], newConstraint[2]);
      assert(oldConstraint[2] === a.version && newConstraint[2] === b.version, 'npm manifest/lock transition mismatch.');
    }
    const floor = stable(newConstraint[2]); const locked = stable(b.version);
    assert(floor[0] === locked[0] && floor[1] === locked[1] && locked[2] >= floor[2]
      && (newConstraint[1] !== '' || locked[2] === floor[2])
      && (newConstraint[1] !== '^' || floor[0] !== 0n || floor[1] !== 0n || locked[2] === floor[2]),
    'npm lock version does not satisfy the supported manifest range.');
    changes.push({ name, from: a.version, to: b.version, section });
  }
  for (const section of ['dependencies', 'devDependencies']) {
    const a = before.manifest[section] ?? {}; const b = after.manifest[section] ?? {};
    assert(object(a) && object(b) && equal(Object.keys(a).sort(), Object.keys(b).sort()), 'npm dependency identities changed.');
    for (const name of Object.keys(a)) assert(a[name] === b[name] || changes.some((change) => change.name === name),
      'npm manifest-only change lacks a proven locked PATCH.');
  }
  assert(changes.length > 0, 'No proven npm PATCH change.');
  return changes;
}

/** Inspect immutable API data with a read-only SDK. This report never grants merge authority. */
export async function evaluate({ github, context, optedIn, mode, checks, ecosystem = 'npm', directory = '.' }) {
  assert(mode === 'evaluate', 'Execution is unavailable until the shared lease/fencing interface is approved and implemented.');
  assert(optedIn === true, 'This repository has not explicitly opted in.');
  assert(context.eventName === 'pull_request', 'Only read-only pull_request events are supported.');
  assert(context.payload.repository?.full_name === `${context.repo.owner}/${context.repo.repo}`, 'Repository identity mismatch.');
  assert(['npm', 'composer'].includes(ecosystem), 'Unsupported ecosystem.');
  assert(directory === '.' || /^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(directory), 'Unsafe dependency directory.');
  const prefix = directory === '.' ? '' : directory + '/';
  const manifestPath = prefix + (ecosystem === 'npm' ? 'package.json' : 'composer.json');
  const lockPath = prefix + (ecosystem === 'npm' ? 'package-lock.json' : 'composer.lock');
  const expected = context.payload.pull_request;
  const request = { ...context.repo, pull_number: expected.number };
  const read = async () => (await github.rest.pulls.get(request)).data;
  const pr = await read();
  const identity = (value) => {
    assert(value.user?.login === 'dependabot[bot]' && value.user.id === 49699333 && value.user.type === 'Bot',
      'Only the official Dependabot identity is supported.');
    assert(value.state === 'open' && !value.draft && !value.merged, 'The PR must be open and ready for review.');
    assert(value.head?.repo?.full_name === context.payload.repository.full_name
      && value.base?.repo?.full_name === context.payload.repository.full_name, 'Forks and repository mismatches are unsupported.');
    assert([value.head.sha, value.base.sha].every((sha) => /^[a-f0-9]{40}$/.test(sha))
      && value.head.sha === expected.head.sha && value.base.sha === expected.base.sha, 'The event HEAD/base snapshot is stale.');
  };
  identity(pr);
  const files = await github.paginate(github.rest.pulls.listFiles, { ...request, per_page: 100 });
  assert(files.length === pr.changed_files && files.length > 0 && files.length <= 2,
    'The complete delta must contain only selected manifest/lock data.');
  assert(files.every((file) => [manifestPath, lockPath].includes(file.filename)
    && file.status === 'modified' && !file.previous_filename)
    && new Set(files.map((file) => file.filename)).size === files.length
    && files.some((file) => file.filename === lockPath), 'Unsupported file, rename or missing lock.');
  const immutableTrees = [];
  for (const ref of [pr.base.sha, pr.head.sha]) {
    const commit = (await github.rest.git.getCommit({ ...context.repo, commit_sha: ref })).data;
    const tree = (await github.rest.git.getTree({ ...context.repo, tree_sha: commit.tree.sha, recursive: '1' })).data;
    assert(tree.truncated === false && [manifestPath, lockPath].every((path) => {
      const entries = tree.tree.filter((entry) => entry.path === path);
      return entries.length === 1 && entries[0].type === 'blob' && entries[0].mode === '100644';
    }), 'Manifest and lock must be complete regular non-executable Git blobs.');
    const entries = tree.tree.filter((entry) => entry.type !== 'tree');
    assert(entries.every((entry) => typeof entry.path === 'string' && /^[a-f0-9]{40}$/.test(entry.sha))
      && new Set(entries.map((entry) => entry.path)).size === entries.length, 'Incomplete immutable Git entries.');
    immutableTrees.push(new Map(entries.map((entry) => [entry.path, {
      sha: entry.sha, type: entry.type, mode: entry.mode,
    }])));
  }
  const [baseTree, headTree] = immutableTrees;
  const changedPaths = [...new Set([...baseTree.keys(), ...headTree.keys()])]
    .filter((path) => !equal(baseTree.get(path), headTree.get(path)));
  assert(changedPaths.length > 0 && changedPaths.length <= 2 && changedPaths.includes(lockPath)
    && changedPaths.every((path) => [manifestPath, lockPath].includes(path)),
  'The full immutable HEAD/base delta contains an unsupported change.');
  const data = async (ref, path) => {
    const response = (await github.rest.repos.getContent({ ...context.repo, ref, path })).data;
    assert(response.type === 'file' && response.encoding === 'base64' && response.size <= 1024 * 1024,
      'Complete bounded regular JSON data is required.');
    const decoded = Buffer.from(response.content, 'base64');
    assert(decoded.length === response.size, 'Incomplete file content.');
    return parseJson(new TextDecoder('utf-8', { fatal: true }).decode(decoded));
  };
  const [oldManifest, newManifest, oldLock, newLock] = await Promise.all([
    data(pr.base.sha, manifestPath), data(pr.head.sha, manifestPath),
    data(pr.base.sha, lockPath), data(pr.head.sha, lockPath),
  ]);
  const changes = (ecosystem === 'npm' ? proveNpm : proveComposer)({ manifest: oldManifest, lock: oldLock }, { manifest: newManifest, lock: newLock });
  assert(Array.isArray(checks) && checks.length >= 2 && checks.every((check) => object(check)
    && typeof check.name === 'string' && check.name.length > 0 && Number.isSafeInteger(check.app_id) && check.app_id > 0)
    && new Set(checks.map((check) => check.name)).size === checks.length,
  'Configure distinct code-review and security checks with trusted App IDs.');
  const runs = await github.paginate(github.rest.checks.listForRef, {
    ...context.repo, ref: pr.head.sha, per_page: 100, filter: 'latest',
  });
  for (const required of checks) {
    const matches = runs.filter((run) => run.name === required.name);
    assert(matches.length === 1 && matches[0].app?.id === required.app_id && matches[0].head_sha === pr.head.sha
      && matches[0].status === 'completed' && matches[0].conclusion === 'success',
    'A configured exact-HEAD review/security check is missing, ambiguous or unsuccessful.');
  }
  identity(await read());
  return { schema: 1, repository: context.payload.repository.full_name, pull_request: pr.number,
    head: pr.head.sha, base: pr.base.sha, ecosystem, directory, patch_candidate: true, changes, checks,
    merge_authorized: false, execution: 'unavailable',
    remaining_gates: ['live-rules-required-checks-reviews-and-threads', 'exact-head-and-base-reviewed-downstream-effects',
      'single-executor-shared-lease-and-fencing'] };
}
