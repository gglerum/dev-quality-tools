<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Console;

use Gglerum\QualityTools\Tools\Category;
use Gglerum\QualityTools\Tools\ToolRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ListCommand extends Command
{
    public function __construct()
    {
        parent::__construct('list-tools');
        $this->setDescription('Show the categories and the tools in them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = new ToolRegistry();
        foreach (Category::cases() as $category) {
            $output->writeln(sprintf('<info>%s</info>  %s', $category->value, $category->title()));
            foreach ($registry->inCategory($category) as $tool) {
                $output->writeln(sprintf('    %-15s %s', $tool->name, $tool->description));
            }
        }
        $output->writeln("\nRun categories and/or tools: <comment>composer analyse sast secrets phpunit</comment>   (no argument or 'all' runs everything)");

        return Command::SUCCESS;
    }
}
