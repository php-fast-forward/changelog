// Offline renderer only: no API, subprocess, credential lookup or remote mutation.
import { readFileSync } from 'node:fs';

const appId = process.argv[2];
if (process.argv.length !== 3 || !/^[1-9][0-9]*$/.test(appId ?? '')
    || !Number.isSafeInteger(Number(appId)) || appId === '15368') {
  console.error('Usage: node .github/release-channel-policy/render.mjs DEDICATED_NUMERIC_APP_ID (not an installation ID or GitHub Actions App)');
  process.exit(2);
}
const configuration = JSON.parse(readFileSync(new URL('./configuration.json', import.meta.url), 'utf8'));
configuration.rulesets[0].bypass_actors[0].actor_id = Number(appId);
process.stdout.write(`${JSON.stringify(configuration, null, 2)}\n`);
