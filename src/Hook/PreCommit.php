<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Hook;

use Gglerum\QualityTools\Project;
use Gglerum\QualityTools\Tools\BinaryLocator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * The pre-commit flow: auto-fixers on the staged files (with a report of what they changed), blocking checks, then tests.
 * Files are modified in place and re-staged; a partially staged file (git add -p) is staged whole.
 */
final class PreCommit
{
    private readonly BinaryLocator $binaries;

    /** @var array<string, list<string>> fixer => files it changed */
    private array $fixed = [];

    public function __construct(
        private readonly Project $project,
        private readonly OutputInterface $output,
    ) {
        $this->binaries = new BinaryLocator($project);
    }

    public function run(): bool
    {
        $this->output->writeln('Running pre-commit checks...');
        $staged = $this->lines($this->git(['diff', '--cached', '--name-only', '--diff-filter=ACM']));
        if ($staged === []) {
            $this->output->writeln('No files staged.');

            return true;
        }

        $php = array_values(array_filter($staged, static fn (string $f): bool => str_ends_with($f, '.php')
            && preg_match('/(_ide_helper.*\.php|\.phpstorm\.meta\.php)$/', $f) !== 1));
        $js = array_values(array_filter($staged, static fn (string $f): bool => preg_match('/\.(js|vue|ts)$/', $f) === 1));
        $codeDirs = array_map(static fn (string $d): string => $d.'/', array_intersect($this->project->settings['paths']['source'], ['app', 'src', 'lib']));
        $appPhp = array_values(array_filter($php, static function (string $f) use ($codeDirs): bool {
            foreach ($codeDirs as $dir) {
                if (str_starts_with($f, $dir)) {
                    return true;
                }
            }

            return false;
        }));

        $this->fix($php, $js, $appPhp);
        $this->reportFixes();

        $ok = $this->check($staged, $php, $appPhp) && $this->test($staged, $php, $js);
        $this->output->writeln($ok ? 'All checks passed. Committing...' : 'Checks failed.');

        return $ok;
    }

    /**
     * @param  list<string>  $php
     * @param  list<string>  $js
     * @param  list<string>  $appPhp
     */
    private function fix(array $php, array $js, array $appPhp): void
    {
        $fixers = $this->project->settings['hook']['fixers'];

        if ($php !== []) {
            // Rector first, then Psalm's annotation fixes, then Pint, so Pint formats what the other two changed
            if (in_array('rector', $fixers, true) && $this->usable('rector', 'rector.php')) {
                $this->fixWith('rector', $php, [...$this->bin('rector'), 'process', '--no-progress-bar', ...$php]);
            }
            if (in_array('psalm-alter', $fixers, true) && $appPhp !== [] && $this->usable('psalm', 'psalm.xml')) {
                $this->fixWith('psalm --alter', $appPhp, [...$this->bin('psalm'), '--no-progress', '--alter', '--issues=MissingPureAnnotation,MissingOverrideAttribute', ...$appPhp], false);
            }
            if (in_array('pint', $fixers, true) && $this->binaries->find('pint') !== null) {
                $this->fixWith('pint', $php, [...$this->bin('pint'), ...$php]);
            }
            $this->git(['add', '--', ...$php]);
        }
        if ($js !== [] && in_array('eslint', $fixers, true) && $this->project->has('node_modules/.bin/eslint')) {
            $this->fixWith('eslint', $js, [$this->project->path('node_modules/.bin/eslint'), '--fix', ...$js]);
            $this->git(['add', '--', ...$js]);
        }
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $command
     */
    private function fixWith(string $name, array $files, array $command, bool $mustSucceed = true): void
    {
        $this->output->writeln("Fixing with {$name}...");
        $before = $this->hashes($files);
        $process = $this->exec($command);
        $after = $this->hashes($files);
        $changed = array_keys(array_filter($after, static fn (string $hash, string $file): bool => ($before[$file] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $this->fixed[$name] = $changed;
        }
        // Rector and Psalm exit non-zero for reasons that are not a failure to fix; Pint/ESLint failing means unfixable style problems
        if ($mustSucceed && ! $process->isSuccessful() && $name !== 'rector') {
            $this->output->write($process->getOutput().$process->getErrorOutput(), false, OutputInterface::OUTPUT_RAW);
        }
    }

    private function reportFixes(): void
    {
        if ($this->fixed === []) {
            $this->output->writeln('Auto-fixers: nothing needed fixing.');

            return;
        }
        $total = count(array_unique(array_merge(...array_values($this->fixed))));
        $this->output->writeln("\nAuto-fixed {$total} file(s); the changes are staged in this commit:");
        foreach ($this->fixed as $fixer => $files) {
            $this->output->writeln(sprintf('  %-14s %d file(s)', $fixer, count($files)));
            foreach ($files as $file) {
                $this->output->writeln("      {$file}");
            }
        }
        $this->output->writeln("Review them with `git diff --cached`.\n");
    }

    /**
     * @param  list<string>  $staged
     * @param  list<string>  $php
     * @param  list<string>  $appPhp
     */
    private function check(array $staged, array $php, array $appPhp): bool
    {
        $checks = $this->project->settings['hook']['checks'];
        $enabled = static fn (string $name): bool => in_array($name, $checks, true);

        if ($php !== []) {
            if ($enabled('phpstan') && $this->usable('phpstan', 'phpstan.neon')
                && ! $this->gate('PHPStan', [...$this->bin('phpstan'), 'analyse', '--no-progress', ...$php])) {
                return false;
            }
            if ($enabled('psalm') && $appPhp !== [] && $this->usable('psalm', 'psalm.xml')
                && ! $this->gate('Psalm', [...$this->bin('psalm'), '--no-progress', '--output-format=compact', ...$appPhp])) {
                return false;
            }
            // Deptrac checks architecture, so it scans everything when any PHP file changes
            if ($enabled('deptrac') && $this->usable('deptrac', 'deptrac.yaml')
                && ! $this->gate('Deptrac', [...$this->bin('deptrac'), 'analyse', '--no-progress', '--no-cache'])) {
                return false;
            }
        }
        if ($enabled('cspell') && $this->project->has('cspell.json') && $this->binaries->node('cspell') !== null
            && ! $this->gate('CSpell', $this->node('cspell', '--no-progress', '--no-must-find-files', ...$staged))) {
            return false;
        }
        if (in_array('composer.lock', $staged, true) && ! $this->gate('Composer audit', ['composer', 'audit'])) {
            return false;
        }
        if (in_array('package-lock.json', $staged, true) && ! $this->gate('npm audit', ['npm', 'audit'])) {
            return false;
        }

        return true;
    }

    /**
     * Only the tests related to the staged PHP files run here: staged test files plus tests that mention a staged
     * class by name. Anything that can change what tests do beyond that (migrations, config, lock files, ...), no
     * related tests found, or FULL_TESTS=1 runs the full suite.
     *
     * @param  list<string>  $staged
     * @param  list<string>  $php
     * @param  list<string>  $js
     */
    private function test(array $staged, array $php, array $js): bool
    {
        if (! $this->project->settings['hook']['tests']) {
            return true;
        }
        $tests = $this->project->settings['paths']['tests'];
        $noImpact = '#^('.preg_quote((string) $tests, '#').'|app|src)/|^(docs|\.claude|\.github|\.vscode)/|\.(md|txt|json|yml|yaml|js|vue|ts|css)$|^(psalm\.xml|phpstan\.neon|rector\.php|project-words\.txt|\.gitignore)$#';
        $full = getenv('FULL_TESTS') === '1';
        foreach ($staged as $file) {
            if (preg_match('/^(composer|package)\.json$/', $file) === 1 || preg_match($noImpact, $file) !== 1) {
                $full = true;
            }
        }
        if ($php === [] && ! $full) {
            return $this->jsTests($js);
        }

        $runner = $this->testRunner();
        if ($runner === null || $tests === null) {
            $this->output->writeln('No PHP test runner found; skipping tests.');

            return $this->jsTests($js);
        }

        $related = array_values(array_filter($php, static fn (string $f): bool => preg_match('#^'.preg_quote($tests, '#').'/.*Test\.php$#', $f) === 1));
        foreach ($php as $file) {
            if (str_starts_with($file, $tests.'/')) {
                continue;
            }
            $found = $this->testsMentioning(pathinfo($file, PATHINFO_FILENAME), $tests);
            if ($found === []) {
                $full = true;
            }
            $related = [...$related, ...$found];
        }
        $related = array_values(array_unique($related));

        if ($full || $related === []) {
            $this->output->writeln('Running the full test suite...');
            $ok = $this->gate('Tests', $runner);
        } else {
            $this->output->writeln(count($related).' related test file(s) (FULL_TESTS=1 forces the full suite).');
            $ok = $this->gate('Tests', [...$runner, ...$related]);
        }

        return $ok && $this->jsTests($js);
    }

    /** @param list<string> $js */
    private function jsTests(array $js): bool
    {
        if ($js === [] || ! $this->project->has('package.json')) {
            return true;
        }
        $package = json_decode((string) file_get_contents($this->project->path('package.json')), true);
        if (! is_array($package) || ! isset($package['scripts']['test'])) {
            return true;
        }

        return $this->gate('JS tests', ['npm', 'run', 'test']);
    }

    /** @return list<string>|null */
    private function testRunner(): ?array
    {
        if ($this->project->isLaravel() && $this->project->has('artisan')) {
            return ['php', 'artisan', 'test', '--compact'];
        }
        $phpunit = $this->binaries->find('phpunit');

        return $phpunit === null ? null : [$phpunit];
    }

    /** @return list<string> */
    private function testsMentioning(string $class, string $testsDir): array
    {
        $process = $this->exec(['grep', '-rlw', '--include=*Test.php', '--exclude-dir=Browser', $class, $testsDir]);

        return $this->lines($process->getOutput());
    }

    /** @param list<string> $command */
    private function gate(string $name, array $command): bool
    {
        $this->output->writeln("Running {$name}...");
        $process = new Process($command, $this->project->root, null, null, null);
        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW));
        if (! $process->isSuccessful()) {
            $this->output->writeln("{$name} failed.");

            return false;
        }

        return true;
    }

    private function usable(string $binary, string $config): bool
    {
        return $this->binaries->find($binary) !== null && $this->project->has($config);
    }

    /** @return list<string> */
    private function bin(string $name): array
    {
        return [(string) $this->binaries->find($name)];
    }

    /** @return list<string> */
    private function node(string $package, string ...$args): array
    {
        return $this->binaries->node($package) === 'npx'
            ? ['npx', '--yes', $package, ...$args]
            : [$this->project->path('node_modules/.bin/'.$package), ...$args];
    }

    /** @param list<string> $command */
    private function exec(array $command): Process
    {
        $process = new Process($command, $this->project->root, null, null, null);
        $process->run();

        return $process;
    }

    /** @param list<string> $args */
    private function git(array $args): string
    {
        return $this->exec(['git', ...$args])->getOutput();
    }

    /**
     * @param  list<string>  $files
     * @return array<string, string>
     */
    private function hashes(array $files): array
    {
        $hashes = [];
        foreach ($files as $file) {
            $path = $this->project->path($file);
            $hashes[$file] = is_file($path) ? (string) md5_file($path) : '';
        }

        return $hashes;
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $l): bool => $l !== ''));
    }
}
