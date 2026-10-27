<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tools;

/** An empty SQLite file for tools that migrate or serve the app, so the dev database is never wiped. */
final class ScratchDatabase
{
    public function __construct(private readonly ToolContext $context) {}

    /**
     * @param callable(array<string, string>): int $callback gets the environment that points the app at the scratch database
     */
    public function with(callable $callback): int
    {
        $dir = $this->context->project->reportsDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir.'/tools.sqlite';
        file_put_contents($file, '');

        return $callback(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file]);
    }
}
