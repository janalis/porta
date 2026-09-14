<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Porta\Container\AsEager;

final class AsEagerTest extends TestCase
{
    public function testTheDefaultPriorityIsZero(): void
    {
        $tags = new AsEager()->tags;

        $this->assertSame(['porta.eager' => ['priority' => 0]], $tags[0]);
    }

    public function testAnExplicitPriorityTravelsAsTheTagAttribute(): void
    {
        $tags = new AsEager(600)->tags;

        $this->assertSame(['porta.eager' => ['priority' => 600]], $tags[0]);
    }
}
