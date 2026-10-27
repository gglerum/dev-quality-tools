<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tests;

use Gglerum\QualityTools\Project;
use Gglerum\QualityTools\Scanner\ProjectScanner;

final class ProjectScannerTest extends TestCase
{
    public function test_detects_a_laravel_project_with_its_directories_and_php_version(): void
    {
        $root = $this->project([
            'composer.json' => '{"require":{"php":"^8.2","laravel/framework":"^12.0"},"config":{"platform":{"php":"8.2.27"}}}',
            'app/' => '', 'routes/' => '', 'tests/' => '',
        ]);

        $settings = (new ProjectScanner($root))->scan();

        $this->assertSame('laravel', $settings['framework']);
        $this->assertSame('8.2', $settings['phpVersion']);
        $this->assertSame(['app', 'routes'], $settings['paths']['source']);
        $this->assertSame('tests', $settings['paths']['tests']);
    }

    public function test_falls_back_to_plain_php_and_the_running_version(): void
    {
        $root = $this->project(['composer.json' => '{}', 'src/' => '']);

        $settings = (new ProjectScanner($root))->scan();

        $this->assertSame('php', $settings['framework']);
        $this->assertSame(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, $settings['phpVersion']);
        $this->assertNull($settings['paths']['tests']);
    }

    public function test_detects_symfony_and_reads_the_minimum_of_a_range(): void
    {
        $root = $this->project(['composer.json' => '{"require":{"php":">=8.1 <8.4","symfony/framework-bundle":"^7"}}']);

        $settings = (new ProjectScanner($root))->scan();

        $this->assertSame('symfony', $settings['framework']);
        $this->assertSame('8.1', $settings['phpVersion']);
    }

    public function test_project_file_overrides_the_scan(): void
    {
        $root = $this->project([
            'composer.json' => '{}',
            'src/' => '',
            '.quality-tools.json' => '{"dast":{"target":"http://localhost:9000"},"disabled":["semgrep"]}',
        ]);

        $project = Project::load($root);

        $this->assertSame('http://localhost:9000', $project->settings['dast']['target']);
        $this->assertSame(8001, $project->settings['dast']['port']);
        $this->assertSame(['semgrep'], $project->settings['disabled']);
    }
}
