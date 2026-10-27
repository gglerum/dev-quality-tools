<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tools;

use Gglerum\QualityTools\Project;

/** Finds a tool binary: Composer's bin dir first, then the downloaded tools, then the PATH. */
final class BinaryLocator
{
    public function __construct(private readonly Project $project) {}

    public function find(string $name): ?string
    {
        $vendor = $this->project->settings['vendorDir'];
        $candidates = [
            $this->project->path($vendor.'/bin/'.$name),
            $this->project->toolsDir().'/'.$name,
            $this->project->toolsDir().'/'.$name.'/bin/'.$name,
            $this->project->toolsDir().'/'.$name.'/'.$name.'.sh',
            $this->project->toolsDir().'/venv/bin/'.$name,
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, '/').'/'.$name;
            if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Node tools run through npx so a project's own node_modules version wins and nothing else is installed globally. */
    public function node(string $package): ?string
    {
        $local = $this->project->path('node_modules/.bin/'.$package);
        if (is_executable($local)) {
            return $local;
        }

        return $this->find('npx') !== null ? 'npx' : null;
    }
}
