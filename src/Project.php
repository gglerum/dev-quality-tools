<?php

declare(strict_types=1);

namespace Gglerum\QualityTools;

use Gglerum\QualityTools\Scanner\ProjectScanner;

/**
 * The project the tools run on: its root, the scan results and the user's overrides from .quality-tools.json.
 *
 * @phpstan-type Settings array{
 *     framework: string,
 *     phpVersion: string,
 *     paths: array{source: list<string>, tests: string|null},
 *     reportsDir: string,
 *     vendorDir: string,
 *     dast: array{target: string|null, port: int},
 *     hook: array{fixers: list<string>, checks: list<string>, tests: bool},
 *     disabled: list<string>
 * }
 */
final class Project
{
    public const CONFIG_FILE = '.quality-tools.json';

    /** @param Settings $settings */
    public function __construct(
        public readonly string $root,
        public readonly array $settings,
    ) {}

    public static function load(string $root): self
    {
        $root = rtrim($root, '/');
        $settings = (new ProjectScanner($root))->scan();

        $file = $root.'/'.self::CONFIG_FILE;
        if (is_file($file)) {
            $overrides = json_decode((string) file_get_contents($file), true);
            if (is_array($overrides)) {
                /** @var Settings $settings */
                $settings = array_replace_recursive($settings, $overrides);
            }
        }

        return new self($root, $settings);
    }

    public function path(string $relative): string
    {
        return $this->root.'/'.ltrim($relative, '/');
    }

    public function has(string $relative): bool
    {
        return file_exists($this->path($relative));
    }

    public function isLaravel(): bool
    {
        return $this->settings['framework'] === 'laravel';
    }

    public function reportsDir(): string
    {
        return $this->path($this->settings['reportsDir']);
    }

    public function toolsDir(): string
    {
        return $this->path($this->settings['vendorDir'].'/bin-tools');
    }

    /** True when composer.json requires (or requires for dev) the package. */
    public function requires(string $package): bool
    {
        $composer = $this->composerJson();

        return isset($composer['require'][$package]) || isset($composer['require-dev'][$package])
            || is_dir($this->path($this->settings['vendorDir'].'/'.$package));
    }

    /** @return array<string, mixed> */
    public function composerJson(): array
    {
        $file = $this->path('composer.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }
}
