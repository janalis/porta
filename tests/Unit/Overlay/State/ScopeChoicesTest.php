<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\State\ScopeChoices;

final class ScopeChoicesTest extends TestCase
{
    public function testLabelFollowsTheIndexAndCyclingWraps(): void
    {
        $scopes = new ScopeChoices(['Whole Project', 'Directory src', 'Open Files'], 1);
        $this->assertSame('Directory src', $scopes->label());

        $scopes = $scopes->cycled();
        $this->assertSame('Open Files', $scopes->label());
        $this->assertSame(0, $scopes->cycled()->index, 'the carousel wraps');
    }

    public function testAnEmptyCarouselHasNoLabelAndCyclingIsSafe(): void
    {
        $scopes = new ScopeChoices([]);
        $this->assertNull($scopes->label());
        $this->assertSame(0, $scopes->cycled()->index, 'no division by zero');
    }
}
