<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tests;

use Gglerum\QualityTools\Config\ConfigGenerator;
use Gglerum\QualityTools\Config\Initializer;
use Gglerum\QualityTools\Project;

final class ConfigGeneratorTest extends TestCase
{
    public function test_configs_use_the_directories_found_in_the_project(): void
    {
        $root = $this->project(['composer.json' => '{"require":{"php":"^8.2"}}', 'src/' => '', 'tests/' => '']);

        $files = (new ConfigGenerator(Project::load($root)))->files();

        $this->assertStringContainsString("        - src\n        - tests", $files['phpstan.neon']);
        $this->assertStringNotContainsString('larastan', $files['phpstan.neon']);
        $this->assertStringContainsString('<directory name="src" />', $files['psalm.xml']);
        $this->assertStringContainsString("__DIR__.'/src'", $files['rector.php']);
        $this->assertStringNotContainsString('app', $files['psalm.xml']);
    }

    public function test_larastan_and_the_psalm_laravel_plugin_are_added_only_when_installed(): void
    {
        $root = $this->project([
            'composer.json' => '{"require":{"laravel/framework":"^12"},"require-dev":{"larastan/larastan":"^3","psalm/plugin-laravel":"^3"}}',
            'app/' => '',
        ]);

        $files = (new ConfigGenerator(Project::load($root)))->files();

        $this->assertStringContainsString('larastan/extension.neon', $files['phpstan.neon']);
        $this->assertStringContainsString('Psalm\LaravelPlugin\Plugin', $files['psalm.xml']);
        $this->assertStringContainsString('#^App\\\\Models\\\\.*#', $files['deptrac.yaml']);
    }

    public function test_generated_json_configs_are_valid(): void
    {
        $root = $this->project(['composer.json' => '{}', 'src/Domain/' => '', 'src/Http/' => '']);

        $files = (new ConfigGenerator(Project::load($root)))->files();

        $this->assertIsArray(json_decode($files['cspell.json'], true));
        $this->assertSame(['src'], json_decode($files['infection.json.dist'], true)['source']['directories']);
        $this->assertStringContainsString('name: Domain', $files['deptrac.yaml']);
        $this->assertStringContainsString('Domain: [Http]', $files['deptrac.yaml']);
    }

    public function test_init_keeps_existing_configs_and_adds_the_composer_script(): void
    {
        $root = $this->project(['composer.json' => '{"name":"a/b"}', 'src/' => '', 'phpstan.neon' => 'mine']);
        $log = [];

        (new Initializer(Project::load($root)))->run(false, function (string $line) use (&$log): void {
            $log[] = $line;
        });

        $this->assertSame('mine', file_get_contents($root.'/phpstan.neon'));
        $this->assertFileExists($root.'/psalm.xml');
        $this->assertFileExists($root.'/'.Project::CONFIG_FILE);
        $this->assertSame('quality-tools analyse', json_decode((string) file_get_contents($root.'/composer.json'), true)['scripts']['analyse']);
        $this->assertStringContainsString('/reports/', (string) file_get_contents($root.'/.gitignore'));
    }
}
