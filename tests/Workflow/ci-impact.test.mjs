import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, readdirSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { classifyDiff, detectImpact } from '../../.github/scripts/ci-impact.mjs';

const record = (path, oldMode = '100644', newMode = '100644', status = 'M') =>
  `:${oldMode} ${newMode} ${'a'.repeat(40)} ${'b'.repeat(40)} ${status}\0${path}\0`;
const diff = (...paths) => Buffer.from(paths.map((path) => record(path)).join(''));

test('release changes skip package gates based on paths, never the branch name', () => {
  assert.deepEqual(classifyDiff(Buffer.from(record('CHANGELOG.md')
    + record('.changelog/fix-publication.md', '100644', '000000', 'D'))), {
    runtime: false, documentation: false,
    reason: 'Only changelog or editorial files changed; contribution validation remains active.',
  });
  assert.equal(classifyDiff(diff('CHANGELOG.md', 'src/Release/ReleasePlanner.php')).runtime, true);
});

test('guides and all their assets build documentation without rerunning the runtime matrix', () => {
  for (const path of ['README.md', 'docs/index.md', 'docs/_static/logo.svg', 'docs/theme.css']) {
    const result = classifyDiff(diff(path));
    assert.equal(result.runtime, false, path);
    assert.equal(result.documentation, true, path);
  }
});

test('all shipped payload, tooling, source, configuration and unknown inputs run every gate', () => {
  for (const path of ['src/Command.php', 'tests/FooTest.php', 'scripts/lint.php', 'composer.json',
    'composer.lock', 'phpdoc.dist.xml', 'Dockerfile', '.dockerignore', '.gitattributes',
    'resources/skills/fast-forward-changelog/SKILL.md', 'resources/workflows/changelog.yml',
    '.github/workflows/repository-ci.yml', '.github/actions/check/action.yml',
    '.github/scripts/ci-impact.mjs', 'a-future-directory/new-input']) {
    const result = classifyDiff(diff(path));
    assert.equal(result.runtime, true, path);
    assert.equal(result.documentation, true, path);
  }
});

test('symlinks, executable modes, gitlinks, renames and malformed comparisons cannot bypass tests', () => {
  for (const raw of [Buffer.alloc(0), Buffer.from('broken'), Buffer.from(record('README.md').slice(0, -1)),
    Buffer.from(record('README.md', '100644', '100755')),
    Buffer.from(record('docs/link', '120000', '120000')),
    Buffer.from(record('docs/module', '160000', '160000')),
    Buffer.from(record('README.md', '100644', '100644', 'R100')),
    Buffer.from(record('docs/invalid\nname.md')), Buffer.from([0xff, 0]),
    Buffer.alloc(8 * 1024 * 1024 + 1)]) {
    assert.equal(classifyDiff(raw).runtime, true);
  }

  // --no-renames exposes both paths, so moving an executable into docs cannot hide it.
  assert.equal(classifyDiff(Buffer.from(record('src/Old.php', '100644', '000000', 'D')
    + record('docs/old.md', '000000', '100644', 'A'))).runtime, true);
});

test('main, manual events, incomplete identities and Git failure retain full validation', () => {
  const forbidden = () => { throw new Error('No comparison should run'); };
  for (const event of ['push', 'workflow_dispatch', 'pull_request_target', undefined]) {
    assert.equal(detectImpact(event, 'a'.repeat(40), 'b'.repeat(40), forbidden).runtime, true);
  }
  for (const identity of ['', '--output=unsafe', 'a'.repeat(39), 'x'.repeat(40)]) {
    assert.equal(detectImpact('pull_request', identity, 'b'.repeat(40), forbidden).runtime, true);
  }
  assert.equal(detectImpact('pull_request', 'a'.repeat(40), 'b'.repeat(40), forbidden).runtime, true);
});

test('real immutable Git history preserves deletes, additions and rename origins without network', () => {
  const directory = mkdtempSync(join(tmpdir(), 'changelog-ci-impact-'));
  const environment = Object.fromEntries(Object.entries(process.env).filter(([name]) =>
    !/^(GIT_|GH_|GITHUB_|FF_CHANGELOG_)/i.test(name)));
  Object.assign(environment, { HOME: directory, GIT_CONFIG_NOSYSTEM: '1',
    GIT_CONFIG_GLOBAL: join(directory, 'empty-config'), GIT_TERMINAL_PROMPT: '0' });
  const git = (...args) => execFileSync('git', args, { cwd: directory, env: environment, encoding: 'utf8' }).trim();
  try {
    writeFileSync(environment.GIT_CONFIG_GLOBAL, '');
    git('init', '--initial-branch=main');
    git('config', 'user.name', 'Synthetic fixture');
    git('config', 'user.email', 'fixture@example.invalid');
    mkdirSync(join(directory, '.changelog'));
    mkdirSync(join(directory, 'src'));
    writeFileSync(join(directory, 'CHANGELOG.md'), '# Changelog\n');
    writeFileSync(join(directory, '.changelog/change.md'), 'Pending change.\n');
    writeFileSync(join(directory, 'src/Old.php'), '<?php return 1;\n');
    git('add', '.');
    git('commit', '-m', 'Fixture base');
    const base = git('rev-parse', 'HEAD');
    writeFileSync(join(directory, 'CHANGELOG.md'), '# Changelog\n\n## [1.0.1]\n');
    rmSync(join(directory, '.changelog/change.md'));
    git('add', '.');
    git('commit', '-m', 'Fixture release');
    const head = git('rev-parse', 'HEAD');
    const output = join(directory, 'output');
    const summary = join(directory, 'summary');
    const detector = fileURLToPath(new URL('../../.github/scripts/ci-impact.mjs', import.meta.url));
    execFileSync(process.execPath, [detector], { cwd: directory, env: { ...environment,
      FF_EVENT: 'pull_request', FF_BASE_SHA: base, FF_HEAD_SHA: head,
      GITHUB_OUTPUT: output, GITHUB_STEP_SUMMARY: summary } });
    assert.equal(readFileSync(output, 'utf8'), 'runtime=false\ndocumentation=false\n');
    assert.match(readFileSync(summary, 'utf8'), /contribution.*remain enabled/);

    mkdirSync(join(directory, 'docs'));
    git('mv', 'src/Old.php', 'docs/old.md');
    git('commit', '-m', 'Fixture code rename');
    const raw = execFileSync('git', ['diff', '--raw', '-z', '--no-renames', `${head}...HEAD`],
      { cwd: directory, env: environment });
    assert.equal(classifyDiff(raw).runtime, true);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

test('the actual workflow guard never executes a contributor-controlled classifier',
  { skip: !process.env.FF_IMPACT_GUARD }, () => {
    const directory = mkdtempSync(join(tmpdir(), 'changelog-trusted-impact-'));
    const environment = Object.fromEntries(Object.entries(process.env).filter(([name]) =>
      !/^(GIT_|GH_|GITHUB_|FF_CHANGELOG_|BASH_ENV$)/i.test(name)));
    Object.assign(environment, { HOME: directory, GIT_CONFIG_NOSYSTEM: '1',
      GIT_CONFIG_GLOBAL: join(directory, 'empty-config'), GIT_TERMINAL_PROMPT: '0' });
    const git = (...args) => execFileSync('git', args,
      { cwd: directory, env: environment, encoding: 'utf8' }).trim();
    let index = 0;
    try {
      writeFileSync(environment.GIT_CONFIG_GLOBAL, '');
      git('init', '--initial-branch=main');
      git('config', 'user.name', 'Synthetic fixture');
      git('config', 'user.email', 'fixture@example.invalid');
      writeFileSync(join(directory, 'README.md'), 'Fixture base without an installed classifier.');
      git('add', '.');
      git('commit', '-m', 'Before classifier adoption');
      const beforeAdoption = git('rev-parse', 'HEAD');
      const helper = '.github/scripts/ci-impact.mjs';
      mkdirSync(join(directory, '.github/scripts'), { recursive: true });
      writeFileSync(join(directory, helper), readFileSync(
        fileURLToPath(new URL('../../.github/scripts/ci-impact.mjs', import.meta.url))));
      git('add', helper);
      git('commit', '-m', 'Protected classifier');
      const trusted = git('rev-parse', 'HEAD');
      const marker = join(directory, 'untrusted-code-executed');
      writeFileSync(join(directory, helper),
        `import {writeFileSync,appendFileSync} from 'node:fs';\n`
        + `writeFileSync(${JSON.stringify(marker)}, 'executed');\n`
        + `appendFileSync(process.env.GITHUB_OUTPUT, 'runtime=false\\ndocumentation=false\\n');\n`);
      mkdirSync(join(directory, 'src'));
      writeFileSync(join(directory, 'src/Changed.php'), '<?php return 1;\n');
      git('add', helper, 'src/Changed.php');
      git('commit', '-m', 'Untrusted code and forged classifier');
      const head = git('rev-parse', 'HEAD');
      const runGuard = (classifier, base = classifier, successful = true) => {
        const output = join(directory, `output-${++index}`);
        const summary = join(directory, `summary-${index}`);
        const options = { cwd: directory, env: { ...environment, RUNNER_TEMP: directory,
          FF_EVENT: 'pull_request', FF_BASE_SHA: base, FF_HEAD_SHA: head,
          FF_CLASSIFIER_SHA: classifier, GITHUB_OUTPUT: output, GITHUB_STEP_SUMMARY: summary },
          stdio: ['ignore', 'pipe', 'pipe'] };
        if (successful) {
          execFileSync('bash', ['-c', process.env.FF_IMPACT_GUARD], options);
          assert.equal(readFileSync(output, 'utf8'), 'runtime=true\ndocumentation=true\n');
        } else {
          assert.throws(() => execFileSync('bash', ['-c', process.env.FF_IMPACT_GUARD], options));
          assert.equal(existsSync(output), false);
        }
        assert.equal(existsSync(marker), false, 'The PR helper must never execute.');
        assert.deepEqual(readdirSync(directory).filter((name) => name.startsWith('changelog-impact.')), []);
      };

      runGuard(trusted); // A protected classifier sees both runtime and helper modifications.
      runGuard(beforeAdoption); // No base helper: static full validation, never the PR helper.
      runGuard('main', 'main', false);
      runGuard('f'.repeat(40), 'f'.repeat(40), false);
      runGuard(head, trusted, false); // A valid head SHA cannot replace the protected base.

      git('update-index', '--chmod=+x', helper);
      git('commit', '-m', 'Unsupported executable classifier');
      runGuard(git('rev-parse', 'HEAD'));
      const blob = execFileSync('git', ['hash-object', '-w', '--stdin'],
        { cwd: directory, env: environment, input: '../outside', encoding: 'utf8' }).trim();
      git('update-index', '--cacheinfo', `120000,${blob},${helper}`);
      git('commit', '-m', 'Unsupported linked classifier');
      runGuard(git('rev-parse', 'HEAD'));
      writeFileSync(join(directory, helper), "throw new Error('Synthetic protected helper failure');\n");
      git('add', helper);
      git('commit', '-m', 'Failing protected classifier');
      runGuard(git('rev-parse', 'HEAD'), undefined, false);
    } finally {
      rmSync(directory, { recursive: true, force: true });
    }
  });
