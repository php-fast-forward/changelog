import { execFileSync } from 'node:child_process';
import { appendFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const full = (reason) => ({ runtime: true, documentation: true, reason });

/** Read complete, NUL-delimited Git records. Unknown paths and modes require all gates. */
export function classifyDiff(raw) {
  if (!Buffer.isBuffer(raw) || raw.length === 0 || raw.length > 8 * 1024 * 1024) {
    return full('The comparison is empty or exceeds the bounded input size.');
  }

  let records;
  try {
    records = new TextDecoder('utf-8', { fatal: true }).decode(raw).split('\0');
  } catch {
    return full('The comparison contains an unsupported path encoding.');
  }

  if (records.pop() !== '' || records.length % 2 !== 0) {
    return full('The Git comparison is incomplete.');
  }

  let documentation = false;
  for (let index = 0; index < records.length; index += 2) {
    const match = /^:(\d{6}) (\d{6}) [a-f0-9]+ [a-f0-9]+ ([ADM])$/.exec(records[index]);
    const path = records[index + 1];
    if (!match || !match.slice(1, 3).every((mode) => ['000000', '100644'].includes(mode))) {
      return full('A changed entry has an executable, linked or unsupported mode.');
    }

    if (/^docs\/[A-Za-z0-9_./-]+$/.test(path) || path === 'README.md') {
      documentation = true;
      continue;
    }

    if (/^\.changelog\/[A-Za-z0-9_-]+\.md$/.test(path) || [
      'CHANGELOG.md', 'AGENTS.md', 'CODE_OF_CONDUCT.md', 'CONTRIBUTING.md',
      'SECURITY.md', 'SUPPORT.md', 'scripts/README.md',
      '.github/PULL_REQUEST_TEMPLATE.md', '.github/update-release-channels.md',
    ].includes(path)) {
      continue;
    }

    // Shipped resources, source, tooling, configuration and future unknown paths stay fully tested.
    return full('Runtime, automation, packaging or unclassified inputs changed.');
  }

  return { runtime: false, documentation, reason: documentation
    ? 'Only guides or editorial files changed; build the documentation.'
    : 'Only changelog or editorial files changed; contribution validation remains active.' };
}

/** Compare immutable PR identities without executing changed files or contacting an API. */
export function detectImpact(event, base, head, git = execFileSync) {
  if (event !== 'pull_request') {
    return full('Main pushes and manual runs validate the complete package.');
  }

  if (![base, head].every((sha) => /^[a-f0-9]{40}$/.test(sha))) {
    return full('A complete immutable PR comparison is unavailable.');
  }

  try {
    return classifyDiff(git('git', [
      'diff', '--raw', '-z', '--no-renames', '--no-ext-diff', '--no-textconv',
      `${base}...${head}`, '--',
    ], { encoding: 'buffer', maxBuffer: 8 * 1024 * 1024, timeout: 30_000 }));
  } catch {
    return full('Git could not prove the complete PR comparison; run all gates.');
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const result = detectImpact(process.env.FF_EVENT, process.env.FF_BASE_SHA, process.env.FF_HEAD_SHA);
  appendFileSync(process.env.GITHUB_OUTPUT, `runtime=${result.runtime}\ndocumentation=${result.documentation}\n`);
  appendFileSync(process.env.GITHUB_STEP_SUMMARY,
    `### Repository validation scope\n\n${result.reason}\n\n`
    + `- Runtime, coverage and packaging: **${result.runtime ? 'run' : 'not needed'}**\n`
    + `- Documentation: **${result.documentation ? 'run' : 'not needed'}**\n`
    + '- Changelog contribution and exact version-PR validation are independent and remain enabled.\n');
}
