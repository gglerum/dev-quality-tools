# Architecture

A Composer plugin plus a Symfony Console app. Namespace `Gglerum\QualityTools`, PSR-4 from `src/`, PHP 8.2 compatible
(no 8.3+ features, whatever PHP you develop on).

## Flow

```
composer install/update ──► Plugin::setup ──► Initializer::run  (configs, composer script, hook, .gitignore)
                                         └──► BinaryInstaller::installAll  (Gitleaks, Trivy, Semgrep; ZAP opt-in)

composer analyse sast ──► bin/quality-tools analyse sast ──► AnalyseCommand ──► ToolRegistry ──► Runner

git commit ──► resources/hooks/pre-commit (sh) ──► bin/quality-tools hook ──► Hook\PreCommit
```

`composer analyse` exists because `Initializer` adds `"analyse": "quality-tools analyse"` to the project's composer
scripts. Composer puts `vendor/bin` on the PATH for scripts and appends the words after `analyse` to the command.

## Files

| Path | Role |
|---|---|
| `src/Plugin.php` | Composer plugin. Subscribes to `post-install-cmd` and `post-update-cmd`. Never throws: failures become warnings. `QUALITY_TOOLS_SKIP=1` skips it; `QUALITY_TOOLS_ZAP=1` also downloads ZAP |
| `src/Project.php` | Root dir + settings (scan merged with `.quality-tools.json`). Helpers: `path()`, `has()`, `requires()`, `isLaravel()`, `reportsDir()`, `toolsDir()` |
| `src/Scanner/ProjectScanner.php` | Reads `composer.json` and the directory layout only; never runs project code. Produces the default settings |
| `src/Config/ConfigGenerator.php` | One method per config file, returns the contents as a string. Adapts to the settings and to installed packages (`Project::requires`) |
| `src/Config/Initializer.php` | Writes the generated files (skips existing ones unless `$force`), edits `composer.json`/`.gitignore`, copies the hook |
| `src/Install/BinaryInstaller.php` | Downloads tools into `<vendor-dir>/bin-tools/`. Versions are constants at the top of the class. Gitleaks/Trivy are SHA-256 verified; ZAP publishes no checksum |
| `src/Tools/Category.php` | The category enum (`sast`, `dast`, `sca`, `secrets`, `style`, `quality`, `tests`, `deploy`) |
| `src/Tools/Tool.php` | A tool definition: name, category, description and either `command` (argv) or `custom` (does its own thing), an `unavailable` check and an optional `stdoutTo` report file |
| `src/Tools/ToolRegistry.php` | All tool definitions. This is where tools are added |
| `src/Tools/BinaryLocator.php` | Finds a binary: `<vendor>/bin`, then `bin-tools`, then PATH. `node()` prefers `node_modules/.bin`, else `npx` |
| `src/Tools/Runner.php` | Runs processes in the project root, streams output, optionally sends stdout to a report file |
| `src/Tools/ScratchDatabase.php`, `ZapRunner.php` | Helpers for Laravel tools that migrate or serve the app on a throwaway SQLite file in `reports/` |
| `src/Console/*` | `analyse`, `list-tools`, `init`, `install`, `hook` |
| `src/Hook/PreCommit.php` | Fixers (with before/after file hashes for the report), blocking checks, related-test selection |
| `resources/hooks/pre-commit` | The sh script copied into `.git/hooks`. Thin on purpose: locale fix, log file via `tee`, fail closed when the exit code file is missing, skip when `quality-tools` is not installed |

## Design decisions

- **Existing configs are never overwritten** by the plugin, so a project's tuning survives `composer update`. Only
  `init --force` overwrites.
- **A category skips unavailable tools; naming a tool makes unavailability a failure.** See `AnalyseCommand::execute`.
- **Laravel-only packages are `suggest`, not `require`**, so plain PHP projects do not install Laravel.
  `ConfigGenerator` checks `Project::requires()` to decide whether to include Larastan, the Psalm plugin and Rector-Laravel.
- **The hook logic is PHP, not sh**, so it can read `.quality-tools.json`, be unit tested and report which files each
  fixer changed.
- **Downloads use `curl` and `tar` through `Process`**, not PHP streams, so proxies and CA config behave like the rest of the machine.
- **Reports** always go under `settings.reportsDir` (default `reports/`) in each tool's own format.
