# Repository validation scope

`ci-impact.mjs` is the internal classifier used by `repository-impact.yml` for
the tests and documentation workflows. It requires Node.js and Git on the Linux
runner; it is excluded from the Composer archive and packaged CLI.

The workflow checks out complete history with read-only permissions and no
persisted credentials. It reads the helper's regular blob from the immutable PR
base, never the contributor's merge-ref copy. On a main push or manual run, the
selected event commit is used. The workflow's `FF_CLASSIFIER_SHA` must be a full
commit identity and match the PR base. If the protected revision has no helper
(first adoption), or its mode/type is unsupported, static Bash selects full
validation without executing any helper. Git failures fail the decision job.
The verified blob runs from a temporary `.mjs` file removed on exit; no dependency
from the PR is installed in this job. Changes to the helper itself still select
full validation through the existing protected policy.

For a PR the helper reads these environment inputs:

| Input | Meaning |
| --- | --- |
| `FF_EVENT` | Original event name; only `pull_request` permits selective execution. |
| `FF_BASE_SHA`, `FF_HEAD_SHA` | Complete immutable 40-character Git identities from the PR event. |
| `GITHUB_OUTPUT` | Runner output file receiving Boolean `runtime` and `documentation` values. |
| `GITHUB_STEP_SUMMARY` | Runner summary file receiving the static explanation of the selected scope. |

Run `node .github/scripts/ci-impact.mjs` from the checked-out repository only with
those explicit output files. The helper reads the complete merge-base diff with
renames disabled, NUL-delimited paths and file modes. It neither fetches from a
network nor executes changed files. Its only writes append to the two runner files.

Ordinary changelog and editorial files do not require package tests; guides and
their assets require documentation. Source, shipped resources, tooling,
configuration and unknown paths require both. Symlinks, executable modes,
gitlinks, malformed or empty comparisons and unsupported path encodings also
require both. Git output is bounded to 8 MiB and 30 seconds. Main pushes, manual
runs, missing identities or failed comparisons choose full validation.

If the helper itself fails or omits an output, dependent jobs fail their first
step. Repair the classifier or Git checkout and rerun the workflow; never edit
the outputs to obtain a passing required context. Contribution and exact version
transaction checks remain independent of this classifier.

Verify locally with `node --test tests/Workflow/ci-impact.test.mjs` and
`composer test:workflows`, then actionlint the three repository workflows.
The fixtures use disposable Git repositories and synthetic identities without
real credentials, consumer installations or publication.
