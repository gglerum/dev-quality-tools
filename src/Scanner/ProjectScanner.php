<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Scanner;

/**
 * Looks at a project directory and works out what the tool configs should contain.
 * Reads only composer.json, package.json and the directory layout; it never runs project code.
 */
final class ProjectScanner
{
    private const SOURCE_CANDIDATES = ['app', 'src', 'config', 'database', 'routes', 'lib'];

    private const TEST_CANDIDATES = ['tests', 'test'];

    public function __construct(private readonly string $root) {}

    /**
     * @return array{
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
    public function scan(): array
    {
        $composer = $this->readJson('composer.json');
        $require = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

        $source = array_values(array_filter(
            self::SOURCE_CANDIDATES,
            fn (string $dir): bool => is_dir($this->root.'/'.$dir),
        ));
        $tests = null;
        foreach (self::TEST_CANDIDATES as $dir) {
            if (is_dir($this->root.'/'.$dir)) {
                $tests = $dir;
                break;
            }
        }

        return [
            'framework' => $this->detectFramework($require),
            'phpVersion' => $this->detectPhpVersion($composer),
            'paths' => ['source' => $source, 'tests' => $tests],
            'reportsDir' => 'reports',
            'vendorDir' => is_string($composer['config']['vendor-dir'] ?? null) ? $composer['config']['vendor-dir'] : 'vendor',
            'dast' => ['target' => null, 'port' => 8001],
            'hook' => [
                'fixers' => ['rector', 'psalm-alter', 'pint', 'eslint'],
                'checks' => ['phpstan', 'psalm', 'deptrac', 'cspell'],
                'tests' => true,
            ],
            'disabled' => [],
        ];
    }

    /** @param array<string, mixed> $require */
    private function detectFramework(array $require): string
    {
        if (isset($require['laravel/framework']) || is_file($this->root.'/artisan')) {
            return 'laravel';
        }
        if (isset($require['symfony/framework-bundle']) || is_file($this->root.'/bin/console')) {
            return 'symfony';
        }

        return 'php';
    }

    /** @param array<string, mixed> $composer */
    private function detectPhpVersion(array $composer): string
    {
        $platform = $composer['config']['platform']['php'] ?? null;
        $constraint = is_string($platform) ? $platform : ($composer['require']['php'] ?? '');

        // The lowest version a constraint like "^8.2", ">=8.1 <8.4" or "8.2.*" allows
        if (is_string($constraint) && preg_match('/(\d+)\.(\d+)/', $constraint, $match) === 1) {
            return $match[1].'.'.$match[2];
        }

        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $path = $this->root.'/'.$file;
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }
}
