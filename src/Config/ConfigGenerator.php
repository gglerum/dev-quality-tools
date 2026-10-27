<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Config;

use Gglerum\QualityTools\Project;

/**
 * Renders the tool config files for one project. Each method returns the file contents;
 * {@see Initializer} decides which are written and whether an existing file is kept.
 */
final class ConfigGenerator
{
    public function __construct(private readonly Project $project) {}

    /** @return array<string, string> file name => contents */
    public function files(): array
    {
        return [
            'phpstan.neon' => $this->phpstan(),
            'psalm.xml' => $this->psalm(),
            'rector.php' => $this->rector(),
            'deptrac.yaml' => $this->deptrac(),
            'cspell.json' => $this->cspell(),
            'project-words.txt' => "# One word per line; CSpell accepts these as correct.\n",
            '.gitleaks.toml' => $this->gitleaks(),
            'trivy.yaml' => $this->trivy(),
            'zap.yaml' => $this->zap(),
            'infection.json.dist' => $this->infection(),
        ];
    }

    /** @return list<string> */
    private function sourceDirs(): array
    {
        return $this->project->settings['paths']['source'];
    }

    /** @return list<string> source dirs plus the tests dir */
    private function analysedDirs(): array
    {
        $tests = $this->project->settings['paths']['tests'];

        return $tests === null ? $this->sourceDirs() : [...$this->sourceDirs(), $tests];
    }

    /** The directories that hold the application classes (not config, routes, ...). */
    private function codeDirs(): array
    {
        $code = array_values(array_intersect($this->sourceDirs(), ['app', 'src', 'lib']));

        return $code === [] ? $this->sourceDirs() : $code;
    }

    private function phpstan(): string
    {
        $includes = $this->project->requires('larastan/larastan')
            ? "includes:\n    - vendor/larastan/larastan/extension.neon\n\n"
            : '';
        $paths = implode("\n", array_map(fn (string $dir): string => '        - '.$dir, $this->analysedDirs()));
        $modelProperties = $this->project->requires('larastan/larastan') ? "    checkModelProperties: true\n" : '';

        return <<<NEON
{$includes}parameters:
    # Level 10 is the strictest; raise it step by step as the errors get fixed.
    level: 5

    paths:
{$paths}

{$modelProperties}    treatPhpDocTypesAsCertain: false
    checkFunctionNameCase: true

NEON;
    }

    private function psalm(): string
    {
        $directories = implode("\n", array_map(
            fn (string $dir): string => '        <directory name="'.$dir.'" />',
            $this->sourceDirs(),
        ));
        $plugin = $this->project->requires('psalm/plugin-laravel')
            ? "    <plugins>\n        <pluginClass class=\"Psalm\\LaravelPlugin\\Plugin\" />\n    </plugins>\n\n"
            : '';
        $vendor = $this->project->settings['vendorDir'];

        return <<<XML
<?xml version="1.0"?>
<psalm
    errorLevel="8"
    resolveFromConfigFile="true"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns="https://getpsalm.org/schema/config"
    xsi:schemaLocation="https://getpsalm.org/schema/config {$vendor}/vimeo/psalm/config.xsd"
    findUnusedBaselineEntry="true"
    findUnusedCode="false"
>
    <projectFiles>
{$directories}
        <ignoreFiles>
            <directory name="{$vendor}" />
        </ignoreFiles>
    </projectFiles>

{$plugin}    <issueHandlers>
        <TaintedInput errorLevel="error" />
        <TaintedShell errorLevel="error" />
        <TaintedSql errorLevel="error" />
        <TaintedFile errorLevel="error" />
        <TaintedInclude errorLevel="error" />
        <TaintedEval errorLevel="error" />
        <TaintedHeader errorLevel="error" />
        <TaintedCookie errorLevel="error" />
        <TaintedHtml errorLevel="error" />
    </issueHandlers>
</psalm>

XML;
    }

    private function rector(): string
    {
        $paths = implode("\n", array_map(
            fn (string $dir): string => "        __DIR__.'/{$dir}',",
            $this->analysedDirs(),
        ));
        $laravel = $this->project->requires('driftingly/rector-laravel');
        $imports = $laravel ? "use RectorLaravel\\Set\\LaravelLevelSetList;\n" : '';
        $sets = $laravel
            ? "    ->withSets([\n        LaravelLevelSetList::UP_TO_LARAVEL_120,\n    ])\n"
            : '';

        return <<<PHP
<?php

declare(strict_types=1);

use Rector\\Config\\RectorConfig;
{$imports}
// The pre-commit hook runs `rector process` on staged files, so these rules rewrite code at commit time.
// Keep the sets conservative; add more once the team is used to them.
return RectorConfig::configure()
    ->withPaths([
{$paths}
    ])
    // PHP version taken from composer.json
    ->withPhpSets()
{$sets}    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);

PHP;
    }

    private function deptrac(): string
    {
        $paths = implode("\n", array_map(fn (string $dir): string => '    - ./'.$dir, $this->codeDirs()));

        return $this->project->isLaravel()
            ? $this->laravelDeptrac($paths)
            : $this->genericDeptrac($paths);
    }

    private function laravelDeptrac(string $paths): string
    {
        $layers = [
            'Model' => 'Models', 'Service' => 'Services', 'Action' => 'Actions',
            'Validation' => '(Rules|Validation|Concerns)', 'Controller' => 'Http\\\\Controllers',
            'Request' => 'Http\\\\Requests', 'Middleware' => 'Http\\\\Middleware', 'Response' => 'Http\\\\Responses',
            'Event' => 'Events', 'Listener' => 'Listeners', 'Notification' => '(Notifications|Mail)',
            'Provider' => 'Providers', 'Console' => 'Console',
        ];
        $definitions = '';
        foreach ($layers as $name => $namespace) {
            $definitions .= "    - name: {$name}\n      collectors:\n        - type: classNameRegex\n          value: '#^App\\\\{$namespace}\\\\.*#'\n";
        }

        return <<<YAML
deptrac:
  paths:
{$paths}
  analyser:
    internal_tag: '@internal'
    types:
      - class
      - file

  layers:
{$definitions}
  # Starting point: the usual Laravel flow. Tighten it as the architecture settles.
  ruleset:
    Model: [Event, Notification]
    Service: [Model, Action, Validation, Event, Notification]
    Action: [Model, Service, Validation, Event, Notification]
    Validation: [Model]
    Controller: [Service, Action, Model, Request, Response, Validation, Notification, Event]
    Request: [Model, Validation]
    Middleware: [Model, Service, Action]
    Event: [Model]
    Listener: [Event, Model, Service, Action, Notification]
    Notification: [Model]
    Console: [Service, Action, Model, Event, Notification]
    Provider: [Controller, Service, Action, Model, Event, Listener, Notification, Middleware, Console, Response, Request]

YAML;
    }

    private function genericDeptrac(string $paths): string
    {
        $layers = [];
        foreach ($this->codeDirs() as $dir) {
            foreach (glob($this->project->path($dir).'/*', GLOB_ONLYDIR) ?: [] as $sub) {
                $layers[] = basename($sub);
            }
        }
        $dir = $this->codeDirs()[0] ?? 'src';
        $definitions = '';
        $ruleset = '';
        foreach ($layers as $name) {
            $others = implode(', ', array_diff($layers, [$name]));
            $definitions .= "    - name: {$name}\n      collectors:\n        - type: directory\n          value: {$dir}/{$name}/.*\n";
            $ruleset .= "    {$name}: [{$others}]\n";
        }
        if ($layers === []) {
            $definitions = "    - name: All\n      collectors:\n        - type: directory\n          value: {$dir}/.*\n";
            $ruleset = "    All: []\n";
        }

        return <<<YAML
deptrac:
  paths:
{$paths}
  layers:
{$definitions}
  # One layer per top-level directory; every layer may use every other one for now.
  # Remove allowed dependencies to turn this into real architecture rules.
  ruleset:
{$ruleset}
YAML;
    }

    private function cspell(): string
    {
        $ignore = ['node_modules/**', 'vendor/**', 'storage/**', 'public/build/**', 'reports/**', '*.log',
            'package-lock.json', 'composer.lock', '*.min.js', '*.min.css', '*.map', '*.sqlite', '*.pdf', '*.png', '*.ico'];
        $json = [
            'version' => '0.2',
            'language' => 'en',
            'dictionaries' => ['php', 'html', 'css', 'softwareTerms', 'npm', 'node', 'bash', 'project-words'],
            'dictionaryDefinitions' => [['name' => 'project-words', 'path' => './project-words.txt', 'addWords' => true]],
            'ignorePaths' => $ignore,
            'reporters' => [['@cspell/cspell-json-reporter', ['outFile' => $this->project->settings['reportsDir'].'/cspell.json']]],
        ];

        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function gitleaks(): string
    {
        $vendor = preg_quote($this->project->settings['vendorDir'], '/');

        return <<<TOML
[extend]
# Use the default gitleaks config as a base
useDefault = true

[[allowlists]]
description = "Composer lock file hashes"
paths = ['''composer\\.lock''']
regexes = ['''"content-hash": "[a-f0-9]+"''', '''"reference": "[a-f0-9]{40}"''']

[[allowlists]]
description = "Package lock file integrity hashes"
paths = ['''package-lock\\.json''']
regexes = ['''"integrity": "sha512-[a-zA-Z0-9+/=]+"''']

[[allowlists]]
description = "Installed dependencies and build output"
paths = ['''^{$vendor}/''', '''^node_modules/''', '''^public/build/''']

TOML;
    }

    private function trivy(): string
    {
        $reports = $this->project->settings['reportsDir'];
        $vendor = $this->project->settings['vendorDir'];

        return <<<YAML
scan:
  scanners:
    - vuln
    - secret
    - misconfig
  # Must sit under `scan:`; at the top level Trivy ignores it without a warning
  skip-dirs:
    - node_modules
    - {$vendor}
    - .git
    - storage
    - {$reports}

format: sarif
output: {$reports}/trivy.sarif

pkg:
  types:
    - library

YAML;
    }

    private function zap(): string
    {
        $reports = $this->project->settings['reportsDir'];

        return <<<YAML
# The target comes from the ZAP_TARGET environment variable; `quality-tools analyse dast` sets it
# (Laravel: it starts the app itself; other projects: set "dast.target" in .quality-tools.json).
env:
  contexts:
    - name: "Default Context"
      urls:
        - "\${ZAP_TARGET}"
      includePaths:
        - "\${ZAP_TARGET}.*"
      excludePaths: []
  parameters:
    failOnError: true
    failOnWarning: false
    progressToStdout: true

jobs:
  - type: spider
    parameters:
      maxDuration: 1
      url: "\${ZAP_TARGET}"

  - type: passiveScan-wait
    parameters:
      maxDuration: 5

  - type: report
    parameters:
      template: "traditional-html"
      reportDir: "{$reports}"
      reportFile: "zap-report.html"

  - type: report
    parameters:
      template: "sarif-json"
      reportDir: "{$reports}"
      reportFile: "zap-report.json"

YAML;
    }

    private function infection(): string
    {
        $reports = $this->project->settings['reportsDir'];
        $json = [
            'source' => ['directories' => $this->codeDirs()],
            'logs' => ['text' => $reports.'/infection.log', 'html' => $reports.'/infection.html', 'gitlab' => $reports.'/infection.json'],
            'mutators' => ['@default' => true],
            'testFramework' => 'phpunit',
            'minMsi' => 0,
            'minCoveredMsi' => 0,
            'timeout' => 20,
        ];

        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
