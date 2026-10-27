<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Console;

use Gglerum\QualityTools\Install\BinaryInstaller;
use Gglerum\QualityTools\Project;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class InstallCommand extends Command
{
    public function __construct()
    {
        parent::__construct('install');
        $this->setDescription('Download the tools Composer cannot install (Gitleaks, Trivy, Semgrep, ZAP)');
        $this->addOption('no-zap', null, InputOption::VALUE_NONE, 'Skip the large ZAP download');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = Project::load((string) getcwd());
        $ok = (new BinaryInstaller($project))->installAll(fn (string $line) => $output->writeln($line), ! $input->getOption('no-zap'));

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
