<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tools;

use Gglerum\QualityTools\Project;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/** Runs processes in the project root and streams their output. */
final class Runner
{
    public function __construct(
        private readonly Project $project,
        private readonly OutputInterface $output,
    ) {}

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @param  string|null  $stdoutTo  report file (relative to the project) that receives stdout instead of the terminal
     */
    public function run(array $command, array $env = [], ?string $stdoutTo = null): int
    {
        $sink = null;
        if ($stdoutTo !== null) {
            $path = $this->project->path($stdoutTo);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            $sink = fopen($path, 'wb');
        }

        $process = new Process($command, $this->project->root, $env === [] ? null : $env, null, null);
        $process->run(function (string $type, string $buffer) use ($sink): void {
            if ($type === Process::OUT && is_resource($sink)) {
                fwrite($sink, $buffer);

                return;
            }
            $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW);
        });
        if (is_resource($sink)) {
            fclose($sink);
        }

        return $process->getExitCode() ?? 1;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function start(array $command, array $env = []): Process
    {
        $process = new Process($command, $this->project->root, $env === [] ? null : $env, null, null);
        $process->start();

        return $process;
    }
}
