<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search\Everywhere;

use PHPUnit\Framework\TestCase;
use Porta\Search\Everywhere\SearchCategory;

final class SearchCategoryTest extends TestCase
{
    public function testTitleOrderAndCap(): void
    {
        $this->assertSame(
            ['Files', 'Classes', 'Symbols', 'Actions', 'Text'],
            array_map(static fn (SearchCategory $c): string => $c->title(), SearchCategory::cases()),
        );
        $this->assertSame(
            [0, 1, 2, 3, 4],
            array_map(static fn (SearchCategory $c): int => $c->order(), SearchCategory::cases()),
        );
        foreach (SearchCategory::cases() as $category) {
            $this->assertSame(5, $category->cap());
        }
    }
}
