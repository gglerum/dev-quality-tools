# Adding a tool

1. **Registry**: add a `new Tool(...)` to the list in `ToolRegistry::definitions()`, under the right category.
   - `command: fn (ToolContext $c): array => $php($c, 'toolname', '--flag')` for a normal process. The helpers
     `$php` (resolved binary), `$node` (npx or `node_modules/.bin`) and `$needsFile($file, $binary)` are defined at the top of the method.
   - Prefer the tool's own output flag (`--report=...`). If it only prints to stdout, set `stdoutTo: 'name.ext'`.
   - `custom: fn (ToolContext $c): int => ...` when it needs setup/teardown (a server, a scratch database). Return the exit code.
   - `unavailable:` returns a reason string (shown as "skipped: ...") or `null` when the tool can run. Check the
     binary, the config file and any project prerequisites.
2. **Config file** (if the tool needs one): add a private method to `ConfigGenerator` and an entry in `files()`.
   Use `$this->project->settings` (directories, PHP version, reports dir) and `$this->project->requires('vendor/package')`
   instead of hard-coding project facts.
3. **Dependency**:
   - available on Packagist → `composer require` it in this package (it becomes a dev dependency of consumers);
   - needs downloading → add a step in `BinaryInstaller::installAll()`, pin the version in a constant, verify a checksum if the release publishes one;
   - needs a Laravel package → add it to `suggest` and to the list in `Initializer::run()`.
4. **Hook** (only if it should run on commit): add it to `PreCommit::fix()` or `check()` and to the defaults in
   `ProjectScanner::scan()` (`hook.fixers` / `hook.checks`).
5. **Tests**: `ToolRegistryTest` already checks that every tool has a category and something to run. Add a
   `ConfigGeneratorTest` case for a new config. `AnalyseCommandTest::test_a_tool_runs_and_its_exit_code_is_reported`
   shows how to test a tool with a fake executable in the temp project's `vendor/bin`.
6. **Docs**: update the category table in `README.md`.

Run the tool in the scratch project (see `development.md`) before committing; the unit tests do not run real tools.
