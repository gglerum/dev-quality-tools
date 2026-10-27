<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tests;

use Gglerum\QualityTools\Console\AnalyseCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyseCommandTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        parent::tearDown();
    }

    public function test_unknown_targets_are_rejected(): void
    {
        chdir($this->project(['composer.json' => '{}']));
        $tester = new CommandTester(new AnalyseCommand);

        $this->assertSame(2, $tester->execute(['targets' => ['sast', 'nonsense']]));
        $this->assertStringContainsString('Unknown: nonsense', $tester->getDisplay());
    }

    public function test_tools_that_are_not_set_up_are_skipped_in_a_category_but_fail_when_named(): void
    {
        chdir($this->project(['composer.json' => '{}']));

        $category = new CommandTester(new AnalyseCommand);
        $this->assertSame(0, $category->execute(['targets' => ['dast']]));
        $this->assertStringContainsString('zap', $category->getDisplay());
        $this->assertStringContainsString('skipped', $category->getDisplay());

        $named = new CommandTester(new AnalyseCommand);
        $this->assertSame(1, $named->execute(['targets' => ['gitleaks']]));
    }

    public function test_a_tool_runs_and_its_exit_code_is_reported(): void
    {
        $root = $this->project(['composer.json' => '{}']);
        mkdir($root.'/vendor/bin', 0777, true);
        file_put_contents($root.'/vendor/bin/pint', "#!/bin/sh\necho '<checkstyle/>'\nexit 1\n");
        chmod($root.'/vendor/bin/pint', 0755);
        chdir($root);

        $tester = new CommandTester(new AnalyseCommand);

        $this->assertSame(1, $tester->execute(['targets' => ['pint']]));
        $this->assertSame("<checkstyle/>\n", file_get_contents($root.'/reports/pint.xml'));
    }
}
