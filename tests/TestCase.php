<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $dir) {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    /** @param array<string, string> $files path => contents; a path ending in "/" creates a directory */
    protected function project(array $files): string
    {
        $dir = sys_get_temp_dir().'/qt-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->directories[] = $dir;
        foreach ($files as $path => $contents) {
            if (str_ends_with($path, '/')) {
                mkdir($dir.'/'.$path, 0777, true);

                continue;
            }
            @mkdir(dirname($dir.'/'.$path), 0777, true);
            file_put_contents($dir.'/'.$path, $contents);
        }

        return $dir;
    }
}
