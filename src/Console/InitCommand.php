<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Console;

use Gglerum\QualityTools\Config\Initializer;
use Gglerum\QualityTools\Project;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class InitCommand extends Command
{
    public function __construct()
    {
        parent::__construct('init');
        $this->setDescription('Scan the project, write the tool configs, add the composer script and install the pre-commit hook');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite configs that already exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = Project::load((string) getcwd());
        $output->writeln(sprintf(
            'Scanned: %s project, PHP %s, source: %s, tests: %s',
            $project->settings['framework'],
            $project->settings['phpVersion'],
            implode(', ', $project->settings['paths']['source']) ?: '(none found)',
            $project->settings['paths']['tests'] ?? '(none found)',
        ));
        (new Initializer($project))->run((bool) $input->getOption('force'), fn (string $line) => $output->writeln($line));

        return Command::SUCCESS;
    }
}
