<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tools;

use Gglerum\QualityTools\Project;

/** What a tool definition gets to work with: the project, where its binaries are and how to run a process. */
final class ToolContext
{
    public function __construct(
        public readonly Project $project,
        public readonly BinaryLocator $binaries,
        public readonly Runner $runner,
    ) {}

    public function report(string $file): string
    {
        return $this->project->settings['reportsDir'].'/'.$file;
    }
}
