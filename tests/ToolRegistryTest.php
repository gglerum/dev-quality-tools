<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tests;

use Gglerum\QualityTools\Tools\Category;
use Gglerum\QualityTools\Tools\ToolRegistry;

final class ToolRegistryTest extends TestCase
{
    public function test_every_category_has_tools_and_every_tool_can_be_run(): void
    {
        $registry = new ToolRegistry;

        foreach (Category::cases() as $category) {
            $this->assertNotEmpty($registry->inCategory($category), $category->value.' has no tools');
        }
        foreach ($registry->all() as $name => $tool) {
            $this->assertSame($name, $tool->name);
            $this->assertTrue($tool->command !== null || $tool->custom !== null, $name.' has nothing to run');
        }
    }

    public function test_tool_names_do_not_collide_with_category_names(): void
    {
        $categories = array_map(fn (Category $c): string => $c->value, Category::cases());

        $this->assertSame([], array_intersect($categories, array_keys((new ToolRegistry)->all())));
    }
}
