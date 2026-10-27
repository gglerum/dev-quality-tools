<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Console;

use Gglerum\QualityTools\Hook\PreCommit;
use Gglerum\QualityTools\Project;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class HookCommand extends Command
{
    public function __construct()
    {
        parent::__construct('hook');
        $this->setDescription('What the git pre-commit hook runs: fix staged files, report the fixes, then check and test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (new PreCommit(Project::load((string) getcwd()), $output))->run() ? Command::SUCCESS : Command::FAILURE;
    }
}
