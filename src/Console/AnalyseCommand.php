<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Console;

use Gglerum\QualityTools\Project;
use Gglerum\QualityTools\Tools\BinaryLocator;
use Gglerum\QualityTools\Tools\Category;
use Gglerum\QualityTools\Tools\Runner;
use Gglerum\QualityTools\Tools\Tool;
use Gglerum\QualityTools\Tools\ToolContext;
use Gglerum\QualityTools\Tools\ToolRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class AnalyseCommand extends Command
{
    public function __construct()
    {
        parent::__construct('analyse');
        $this->setDescription('Run categories (sast, dast, sca, ...) and/or single tools (phpstan, gitleaks, ...)');
        $this->addArgument('targets', InputArgument::IS_ARRAY, 'Categories and/or tool names; "all" or nothing runs everything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = Project::load((string) getcwd());
        $registry = new ToolRegistry;

        /** @var list<string> $targets */
        $targets = $input->getArgument('targets');
        [$tools, $unknown] = $this->resolve($registry, $targets, $project);
        if ($unknown !== []) {
            $output->writeln('<error>Unknown: '.implode(', ', $unknown).'</error> (see `vendor/bin/quality-tools list-tools`)');

            return Command::INVALID;
        }

        $reports = $project->reportsDir();
        if (! is_dir($reports)) {
            mkdir($reports, 0777, true);
        }
        $context = new ToolContext($project, new BinaryLocator($project), new Runner($project, $output));
        $explicit = array_values(array_filter($targets, fn (string $t): bool => $registry->get($t) !== null));

        $results = [];
        foreach ($tools as $tool) {
            $output->writeln("<info>=== {$tool->name}</info>");
            $reason = $tool->unavailable !== null ? ($tool->unavailable)($context) : null;
            if ($reason !== null) {
                $output->writeln("skipped: {$reason}");
                // Asked for by name: not being able to run it is a failure; as part of a category it is only a skip
                $results[$tool->name] = in_array($tool->name, $explicit, true) ? 'unavailable' : 'skipped';

                continue;
            }
            $code = $tool->custom !== null
                ? ($tool->custom)($context)
                : $context->runner->run(($tool->command)($context), [], $tool->stdoutTo !== null ? $context->report($tool->stdoutTo) : null);
            $results[$tool->name] = $code;
        }

        return $this->summary($output, $results, $project->settings['reportsDir']);
    }

    /**
     * @param  list<string>  $targets
     * @return array{list<Tool>, list<string>}
     */
    private function resolve(ToolRegistry $registry, array $targets, Project $project): array
    {
        if ($targets === [] || in_array('all', $targets, true)) {
            $targets = array_map(fn (Category $c): string => $c->value, Category::cases());
        }

        $tools = [];
        $unknown = [];
        foreach ($targets as $target) {
            $category = Category::tryFrom($target);
            if ($category !== null) {
                foreach ($registry->inCategory($category) as $tool) {
                    $tools[$tool->name] = $tool;
                }
            } elseif (($tool = $registry->get($target)) !== null) {
                $tools[$tool->name] = $tool;
            } else {
                $unknown[] = $target;
            }
        }
        $disabled = $project->settings['disabled'];

        return [array_values(array_filter($tools, fn (Tool $t): bool => ! in_array($t->name, $disabled, true))), $unknown];
    }

    /** @param array<string, int|string> $results */
    private function summary(OutputInterface $output, array $results, string $reportsDir): int
    {
        $output->writeln("\nSummary (exit code; non-zero = findings or tool error, details in {$reportsDir}/):");
        $failed = false;
        foreach ($results as $name => $result) {
            $ok = $result === 0;
            $skipped = $result === 'skipped';
            $failed = $failed || (! $ok && ! $skipped);
            $label = $ok ? '<info>ok</info>' : ($skipped ? '<comment>skipped</comment>' : "<error>{$result}</error>");
            $output->writeln(sprintf('  %-15s %s', $name, $label));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
