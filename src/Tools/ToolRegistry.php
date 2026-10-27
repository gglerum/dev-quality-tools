<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tools;

/** Every tool the package knows, grouped by category. */
final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct()
    {
        foreach ($this->definitions() as $tool) {
            $this->tools[$tool->name] = $tool;
        }
    }

    /** @return array<string, Tool> */
    public function all(): array
    {
        return $this->tools;
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return list<Tool> */
    public function inCategory(Category $category): array
    {
        return array_values(array_filter($this->tools, fn (Tool $tool): bool => $tool->category === $category));
    }

    /** @return list<Tool> */
    private function definitions(): array
    {
        $needsFile = static fn (string $file, string $binary): \Closure => static function (ToolContext $c) use ($file, $binary): ?string {
            if ($c->binaries->find($binary) === null) {
                return "{$binary} is not installed (run: vendor/bin/quality-tools install)";
            }

            return $c->project->has($file) ? null : "{$file} is missing (run: vendor/bin/quality-tools init)";
        };
        $needsNpm = static fn (string $package): \Closure => static function (ToolContext $c) use ($package): ?string {
            if (! $c->project->has('package.json')) {
                return 'no package.json';
            }

            return $c->binaries->node($package) === null ? 'node/npx is not installed' : null;
        };
        $node = static fn (ToolContext $c, string $package, string ...$args): array => $c->binaries->node($package) === 'npx'
            ? ['npx', '--yes', $package, ...$args]
            : [$c->project->path('node_modules/.bin/'.$package), ...$args];
        $php = static fn (ToolContext $c, string $binary, string ...$args): array => [(string) $c->binaries->find($binary), ...$args];
        $artisan = static fn (ToolContext $c): ?string => $c->project->isLaravel() && $c->project->has('artisan') ? null : 'only for Laravel projects';

        return [
            // SAST
            new Tool('phpstan', Category::Sast, 'PHP static analysis (JSON)',
                command: fn (ToolContext $c): array => $php($c, 'phpstan', 'analyse', '--no-progress', '--error-format=json'),
                unavailable: $needsFile('phpstan.neon', 'phpstan'),
                stdoutTo: 'phpstan.json'),
            new Tool('psalm', Category::Sast, 'PHP type analysis (SARIF)',
                command: fn (ToolContext $c): array => $php($c, 'psalm', '--no-progress', '--no-cache', '--report='.$c->report('psalm.sarif')),
                unavailable: $needsFile('psalm.xml', 'psalm')),
            new Tool('psalm-taint', Category::Sast, 'PHP taint analysis: user input reaching SQL, HTML, shell, ... (SARIF)',
                command: fn (ToolContext $c): array => $php($c, 'psalm', '--no-progress', '--no-cache', '--taint-analysis', '--report='.$c->report('psalm-taint.sarif')),
                unavailable: $needsFile('psalm.xml', 'psalm')),
            new Tool('deptrac', Category::Sast, 'Architecture layer rules (JSON)',
                command: fn (ToolContext $c): array => $php($c, 'deptrac', 'analyse', '--no-progress', '--no-cache', '--formatter=json', '--output='.$c->report('deptrac.json')),
                unavailable: $needsFile('deptrac.yaml', 'deptrac')),
            new Tool('semgrep', Category::Sast, 'Security and bug patterns (SARIF, JSON)',
                command: fn (ToolContext $c): array => $php($c, 'semgrep', 'scan', '--config', 'p/default', '--config', 'p/php', '--metrics=off', '--quiet',
                    '--sarif-output='.$c->report('semgrep.sarif'), '--json-output='.$c->report('semgrep.json')),
                unavailable: static fn (ToolContext $c): ?string => $c->binaries->find('semgrep') === null ? 'semgrep is not installed (run: vendor/bin/quality-tools install)' : null),

            // DAST
            new Tool('zap', Category::Dast, 'OWASP ZAP scan of the running app (HTML, SARIF)',
                custom: fn (ToolContext $c): int => (new ZapRunner)->run($c),
                unavailable: static function (ToolContext $c): ?string {
                    if ($c->binaries->find('zap.sh') === null) {
                        return 'ZAP is not installed (run: vendor/bin/quality-tools install)';
                    }
                    if (! $c->project->has('zap.yaml')) {
                        return 'zap.yaml is missing (run: vendor/bin/quality-tools init)';
                    }

                    return $c->project->isLaravel() || $c->project->settings['dast']['target'] !== null
                        ? null
                        : 'set "dast.target" (the URL of a running app) in .quality-tools.json';
                }),

            // SCA
            new Tool('composer-audit', Category::Sca, 'Known vulnerable or abandoned PHP packages (JSON)',
                command: static fn (ToolContext $c): array => ['composer', 'audit', '--locked', '--format=json'],
                unavailable: static fn (ToolContext $c): ?string => $c->project->has('composer.lock') ? null : 'no composer.lock',
                stdoutTo: 'composer-audit.json'),
            new Tool('npm-audit', Category::Sca, 'Known vulnerable npm packages (JSON)',
                command: static fn (ToolContext $c): array => ['npm', 'audit', '--json'],
                unavailable: static fn (ToolContext $c): ?string => $c->project->has('package-lock.json') ? null : 'no package-lock.json',
                stdoutTo: 'npm-audit.json'),
            new Tool('trivy', Category::Sca, 'Vulnerable dependencies, secrets, misconfigurations (SARIF)',
                command: fn (ToolContext $c): array => $php($c, 'trivy', 'fs', '--config', 'trivy.yaml', '--quiet', '--exit-code', '1', '.'),
                unavailable: $needsFile('trivy.yaml', 'trivy')),

            // Secrets
            new Tool('gitleaks', Category::Secrets, 'Secrets in the whole git history (JSON)',
                command: fn (ToolContext $c): array => $php($c, 'gitleaks', 'git', '.', '--config', '.gitleaks.toml', '--redact', '--no-banner',
                    '--report-format', 'json', '--report-path', $c->report('gitleaks.json')),
                unavailable: $needsFile('.gitleaks.toml', 'gitleaks')),

            // Style
            new Tool('pint', Category::Style, 'PHP code style, check only (checkstyle XML)',
                command: fn (ToolContext $c): array => $php($c, 'pint', '--test', '--format=checkstyle'),
                unavailable: static fn (ToolContext $c): ?string => $c->binaries->find('pint') === null ? 'pint is not installed' : null,
                stdoutTo: 'pint.xml'),
            new Tool('prettier', Category::Style, 'Frontend formatting check (text)',
                command: fn (ToolContext $c): array => $node($c, 'prettier', '--check', 'resources/'),
                unavailable: static fn (ToolContext $c): ?string => $c->project->has('resources') ? $needsNpm('prettier')($c) : 'no resources/ directory',
                stdoutTo: 'prettier.txt'),
            new Tool('eslint', Category::Style, 'JS/TS lint (JSON)',
                command: fn (ToolContext $c): array => $node($c, 'eslint', '.', '--format', 'json', '--output-file', $c->report('eslint.json')),
                unavailable: static fn (ToolContext $c): ?string => $c->project->has('eslint.config.js') || $c->project->has('eslint.config.mjs') || $c->project->has('.eslintrc.json')
                    ? $needsNpm('eslint')($c) : 'no ESLint config'),
            new Tool('cspell', Category::Style, 'Spelling (JSON)',
                command: fn (ToolContext $c): array => $node($c, 'cspell', '**', '--no-progress'),
                unavailable: static fn (ToolContext $c): ?string => $c->project->has('cspell.json') ? ($c->binaries->node('cspell') === null ? 'node/npx is not installed' : null) : 'cspell.json is missing (run: vendor/bin/quality-tools init)'),

            // Quality
            new Tool('rector', Category::Quality, 'Outdated or refactorable PHP, dry run (JSON)',
                command: fn (ToolContext $c): array => $php($c, 'rector', 'process', '--dry-run', '--output-format=json', '--no-progress-bar'),
                unavailable: $needsFile('rector.php', 'rector'),
                stdoutTo: 'rector.json'),
            new Tool('types', Category::Quality, 'TypeScript type check (text)',
                command: static fn (ToolContext $c): array => ['npx', 'tsc', '--noEmit'],
                unavailable: static fn (ToolContext $c): ?string => $c->project->has('tsconfig.json') ? $needsNpm('typescript')($c) : 'no tsconfig.json',
                stdoutTo: 'types.txt'),

            // Tests
            new Tool('phpunit', Category::Tests, 'PHP tests with coverage (JUnit XML, Clover)',
                command: fn (ToolContext $c): array => $c->project->isLaravel() && $c->project->has('artisan')
                    ? ['php', 'artisan', 'test', '--coverage-clover='.$c->report('coverage.xml'), '--log-junit='.$c->report('junit.xml')]
                    : $php($c, 'phpunit', '--coverage-clover='.$c->report('coverage.xml'), '--log-junit='.$c->report('junit.xml')),
                unavailable: static fn (ToolContext $c): ?string => $c->binaries->find('phpunit') === null ? 'phpunit is not installed' : null),
            new Tool('infection', Category::Tests, 'Mutation testing, needs the phpunit reports (HTML, JSON)',
                command: fn (ToolContext $c): array => $php($c, 'infection', '--threads=4', '--no-progress', '--coverage='.$c->project->settings['reportsDir'], '--skip-initial-tests'),
                unavailable: static function (ToolContext $c) use ($needsFile): ?string {
                    $reason = $needsFile('infection.json.dist', 'infection')($c);

                    return $reason ?? ($c->project->has($c->report('junit.xml')) ? null : 'run the phpunit tool first (it writes the coverage infection reuses)');
                }),
            new Tool('vitest', Category::Tests, 'JS tests with coverage (HTML)',
                command: fn (ToolContext $c): array => $node($c, 'vitest', 'run', '--coverage'),
                unavailable: static fn (ToolContext $c): ?string => $c->project->has('vitest.config.ts') || $c->project->has('vitest.config.js') ? $needsNpm('vitest')($c) : 'no Vitest config'),

            // Deploy (Laravel)
            new Tool('migrations', Category::Deploy, 'Every migration runs up, down and up again, on a scratch database (text)',
                custom: static fn (ToolContext $c): int => (new ScratchDatabase($c))->with(
                    static fn (array $env): int => $c->runner->run(['bash', '-c', 'php artisan migrate --force --seed && php artisan migrate:reset --force && php artisan migrate --force'], $env, $c->report('migrations.txt')),
                ),
                unavailable: $artisan),
            new Tool('deploy-check', Category::Deploy, 'route:cache, config:cache and view:cache succeed (text)',
                custom: static function (ToolContext $c): int {
                    $status = $c->runner->run(['bash', '-c', 'php artisan route:cache && php artisan config:cache && php artisan view:cache'], [], $c->report('deploy-check.txt'));
                    $c->runner->run(['php', 'artisan', 'optimize:clear']);

                    return $status;
                },
                unavailable: $artisan),
        ];
    }
}
