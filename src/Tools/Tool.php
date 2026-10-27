<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tools;

use Closure;

/**
 * One tool in one category. Either `command` builds a process command line, or `custom` runs the whole thing
 * (for tools that need a server, a scratch database, ...).
 */
final class Tool
{
    /**
     * @param Closure(ToolContext): list<string>|null $command argv of the process; null when `custom` is used
     * @param Closure(ToolContext): int|null $custom runs the tool itself and returns the exit code
     * @param Closure(ToolContext): (string|null)|null $unavailable reason the tool cannot run here, or null when it can
     * @param string|null $stdoutTo file in the reports dir that receives stdout (for tools without an output flag)
     */
    public function __construct(
        public readonly string $name,
        public readonly Category $category,
        public readonly string $description,
        public readonly ?Closure $command = null,
        public readonly ?Closure $custom = null,
        public readonly ?Closure $unavailable = null,
        public readonly ?string $stdoutTo = null,
    ) {}
}
