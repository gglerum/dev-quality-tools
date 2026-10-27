<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Config;

use Gglerum\QualityTools\Project;
use Symfony\Component\Process\Process;

/**
 * Writes the scanned configs, the .quality-tools.json file, the composer script and the git hook.
 * Existing files are kept unless $force is set, so a project's own tuning is never overwritten.
 */
final class Initializer
{
    public function __construct(private readonly Project $project) {}

    /**
     * @param callable(string): void $log
     */
    public function run(bool $force, callable $log): void
    {
        foreach ((new ConfigGenerator($this->project))->files() as $file => $contents) {
            $this->write($file, $contents, $force, $log);
        }

        $this->write(Project::CONFIG_FILE, json_encode($this->project->settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", $force, $log);
        $this->addComposerScript($log);
        $this->installHook($log);
        $this->addGitignore($log);

        if ($this->project->isLaravel()) {
            $missing = array_filter(
                ['larastan/larastan', 'psalm/plugin-laravel', 'driftingly/rector-laravel'],
                fn (string $package): bool => ! $this->project->requires($package),
            );
            if ($missing !== []) {
                $log('Laravel project: for the Laravel rules also run `composer require --dev '.implode(' ', $missing).'`, then `vendor/bin/quality-tools init --force`.');
            }
        }
    }

    /** @param callable(string): void $log */
    private function write(string $file, string $contents, bool $force, callable $log): void
    {
        $path = $this->project->path($file);
        if (is_file($path) && ! $force) {
            $log("kept     {$file} (exists; --force overwrites)");

            return;
        }
        file_put_contents($path, $contents);
        $log("wrote    {$file}");
    }

    /** @param callable(string): void $log */
    private function addComposerScript(callable $log): void
    {
        $path = $this->project->path('composer.json');
        if (! is_file($path)) {
            return;
        }
        $raw = (string) file_get_contents($path);
        $composer = json_decode($raw, true);
        if (! is_array($composer) || isset($composer['scripts']['analyse'])) {
            return;
        }
        $composer['scripts']['analyse'] = 'quality-tools analyse';
        // Re-encoding keeps the data but not custom formatting; keep Composer's own style
        file_put_contents($path, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $log('added    composer script "analyse" (composer analyse sast dast ...)');
    }

    /** @param callable(string): void $log */
    private function addGitignore(callable $log): void
    {
        $path = $this->project->path('.gitignore');
        $entry = '/'.$this->project->settings['reportsDir'].'/';
        $current = is_file($path) ? (string) file_get_contents($path) : '';
        if (str_contains($current, $entry)) {
            return;
        }
        file_put_contents($path, rtrim($current)."\n{$entry}\n/".$this->project->settings['vendorDir']."/bin-tools/\n");
        $log('updated  .gitignore (reports, downloaded tools)');
    }

    /** @param callable(string): void $log */
    private function installHook(callable $log): void
    {
        $git = new Process(['git', 'rev-parse', '--git-path', 'hooks'], $this->project->root);
        $git->run();
        if (! $git->isSuccessful()) {
            $log('skipped  pre-commit hook (not a git repository)');

            return;
        }
        $hooksDir = trim($git->getOutput());
        if (! str_starts_with($hooksDir, '/')) {
            $hooksDir = $this->project->root.'/'.$hooksDir;
        }
        if (! is_dir($hooksDir)) {
            mkdir($hooksDir, 0777, true);
        }

        $target = $hooksDir.'/pre-commit';
        $source = dirname(__DIR__, 2).'/resources/hooks/pre-commit';
        $contents = (string) file_get_contents($source);
        if (is_file($target) && (string) file_get_contents($target) !== $contents) {
            copy($target, $target.'.bak');
            $log('backup   existing pre-commit hook saved as pre-commit.bak');
        }
        file_put_contents($target, $contents);
        chmod($target, 0755);
        $log('installed pre-commit hook in '.$hooksDir);
    }
}
