<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Console;

use Symfony\Component\Console\Application as BaseApplication;

final class Application extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('quality-tools', '1.0.0');
        $this->addCommands([
            new AnalyseCommand(),
            new ListCommand(),
            new InitCommand(),
            new InstallCommand(),
            new HookCommand(),
        ]);
    }
}
