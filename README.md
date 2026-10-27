# dev-quality-tools

One dev dependency that gives a PHP project its security and quality tooling: SAST, DAST, SCA, secret scanning,
style, tests and deploy checks. It scans the project, writes tool configs that fit it, adds a `composer analyse`
script and installs a git pre-commit hook that fixes staged files and reports what it changed.

Works for Laravel (best supported), Symfony and plain PHP projects. PHP 8.2+.

## Install

The package is not on Packagist yet, so add its GitHub repository to your project's `composer.json` first:

```bash
composer config repositories.quality-tools vcs https://github.com/gglerum/dev-quality-tools
```

This adds the entry below (you can also paste it by hand):

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/gglerum/dev-quality-tools" }
]
```

Then allow the Composer plugin and require the package as a dev dependency:

```bash
composer config allow-plugins.gglerum/dev-quality-tools true
composer require --dev gglerum/dev-quality-tools:dev-main
```

`dev-main` is needed until a version tag exists. The repository is public and the HTTPS URL needs no credentials.

After `composer install` / `update` the plugin:

1. scans the project (framework, PHP version, `app`/`src`/`tests`/... directories, installed Laravel packages)
   and writes `phpstan.neon`, `psalm.xml`, `rector.php`, `deptrac.yaml`, `cspell.json`, `.gitleaks.toml`,
   `trivy.yaml`, `zap.yaml`, `infection.json.dist` and `.quality-tools.json`. Existing files are never overwritten;
2. adds `"analyse": "quality-tools analyse"` to your composer scripts and `reports/` plus the tools directory to `.gitignore`;
3. installs the pre-commit hook in the git hooks directory (an existing hook is saved as `pre-commit.bak`);
4. downloads Gitleaks and Trivy (pinned versions, SHA-256 verified) and Semgrep (Python venv) into `vendor/bin-tools/`.
   Tools already in `vendor/bin` or on your `PATH` are used instead. ZAP is a large download: run
   `vendor/bin/quality-tools install` or set `QUALITY_TOOLS_ZAP=1` during `composer install`.

A failure in any of this only prints a warning; it never fails `composer install`. `QUALITY_TOOLS_SKIP=1` skips the
whole setup (CI, Docker builds). Re-run it by hand with `vendor/bin/quality-tools init [--force]` and `install`.

For Laravel, also `composer require --dev larastan/larastan psalm/plugin-laravel driftingly/rector-laravel`, then
`vendor/bin/quality-tools init --force` to regenerate configs that use them (they are kept out of the package so
non-Laravel projects do not pull in Laravel).

## Using it

Everything goes through the `analyse` composer script that `init` adds. Composer passes the words after it on to the tool:

```bash
composer analyse                   # everything
composer analyse sast dast         # one or more categories
composer analyse phpstan gitleaks  # single tools
composer analyse sast gitleaks     # categories and tools mixed
vendor/bin/quality-tools list-tools   # all categories and tools
```

Other commands (run them with `vendor/bin/quality-tools`):

| Command | What it does |
|---|---|
| `analyse [targets...]` | Run categories and/or tools (what `composer analyse` calls) |
| `list-tools` | Show the categories and the tools in them |
| `init [--force]` | Rescan the project and write the configs, composer script and hook; `--force` overwrites existing configs |
| `install [--no-zap]` | Download Gitleaks, Trivy, Semgrep and ZAP into `vendor/bin-tools/` |
| `hook` | What the pre-commit hook runs; you can run it by hand to try it |

Typical first run in a project: `composer analyse sast secrets`, then look in `reports/`. Adjust the generated
configs, or `.quality-tools.json`, until the output is useful, then run `composer analyse` in CI.

Reports go to `reports/` in each tool's own format (SARIF, JSON, JUnit, ...). The exit code is non-zero when a tool
finds something. A tool that cannot run (not installed, no config) is *skipped* when it came from a category, and
*fails* when you named it.

| Category | Tools |
|---|---|
| `sast` | phpstan, psalm, psalm-taint, deptrac, semgrep |
| `dast` | zap |
| `sca` | composer-audit, npm-audit, trivy |
| `secrets` | gitleaks |
| `style` | pint (check), prettier, eslint, cspell |
| `quality` | rector (dry run), types (tsc) |
| `tests` | phpunit, infection, vitest |
| `deploy` | migrations, deploy-check (Laravel) |

DAST on Laravel starts the app on a scratch SQLite database. For other projects set `dast.target` to the URL of a
running instance.

## Pre-commit hook

On every commit it runs, on the staged files only: Rector, Psalm `--alter`, Pint (PHP) and ESLint `--fix` (JS), and
re-stages what they changed. **It then lists, per fixer, which files it changed.** After the fixers it blocks the
commit on PHPStan, Psalm, Deptrac, CSpell, `composer audit` / `npm audit` (when a lock file changed) and the tests
related to the staged files (`FULL_TESTS=1` runs the whole suite). Output is also written to `.git/pre-commit.log`.
Tools that are not installed or configured are skipped.

## Configuration: `.quality-tools.json`

Written by `init` from the scan; edit it freely, it overrides the scan.

```json
{
    "framework": "laravel",
    "phpVersion": "8.2",
    "paths": { "source": ["app", "routes"], "tests": "tests" },
    "reportsDir": "reports",
    "dast": { "target": null, "port": 8001 },
    "hook": { "fixers": ["rector", "psalm-alter", "pint", "eslint"], "checks": ["phpstan", "psalm", "deptrac", "cspell"], "tests": true },
    "disabled": ["semgrep"]
}
```

## Development

```bash
composer install
composer test
```
