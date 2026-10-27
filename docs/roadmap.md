# Known gaps and next steps

Honest list of what is untested or incomplete at the time of writing (2026-10).

## Bugs and sharp edges

- **List overrides merge by index.** `Project::load()` uses `array_replace_recursive`. If `.quality-tools.json` sets
  `paths.source` to `["app"]` and the scan found `["app","routes"]`, the result is still `["app","routes"]`. Lists in
  the user file should replace, not merge. Needs a small merge function plus a test in `ProjectScannerTest`.
- **`.quality-tools.json` freezes the scan.** `init` writes the whole scan into it, and the file then overrides every
  later scan, so a directory added later is not picked up. Consider writing only user-intended keys, or a `rescan` command.
- **`init` rewrites `composer.json`** with `json_encode` when adding the `analyse` script, which can reformat the file
  (indentation, key escaping). Safer: use Composer's `JsonManipulator` (available when running inside Composer) or `composer config scripts.analyse ...`.
- **`psalm --alter` and the code dirs** in the hook only look at `app/`, `src/`, `lib/`.
- **Rector's exit code is ignored** in the hook's fixer step on purpose; a Rector crash would go unnoticed.

## Never run for real

These exist and are unit-tested only for their wiring, not executed against a real project:

- ZAP (`ZapRunner`, the Linux tarball install), `migrations` and `deploy-check`.
- Semgrep install: failed on a machine without `python3-venv`; the error handling was exercised, the success path was not.
- npm tools in the hook: CSpell failed on Node 18 (needs 22+). The hook correctly blocked, but the message could say "Node too old".
- macOS downloads for Gitleaks/Trivy (asset-name mapping only), Windows (unsupported).
- The GitHub Actions workflow `tests.yml` has not run on a PHP 8.2 matrix entry yet.
- Real Laravel project with Larastan/Psalm-Laravel/Rector-Laravel installed (the Retail Safari repo is the obvious test).

## Ideas

- Retail Safari integration: replace `utils/tools.sh`, the Dockerfile tool installs and `utils/hooks/pre-commit` with this
  package (existing configs are kept by `init`). Compare the generated `deptrac.yaml`/`cspell.json` with the hand-tuned ones.
- `doctor` command: list every tool, whether it is found, where, and its version.
- `--report-dir`, `--fail-fast` and a machine-readable summary (JSON) for CI.
- Parallel execution of independent tools.
- Pre-commit: run the hook against a stash so unstaged changes are not touched/staged (today a partially staged file is staged whole).
- Add PHPStan to the package's own CI; add Packagist publication and a first tag.
- Windows support (hook is sh, downloads assume curl/tar).
