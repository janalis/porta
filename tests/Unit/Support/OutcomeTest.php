<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\Outcome;

final class OutcomeTest extends TestCase
{
    public function testABoolConvertsToItsOutcomeAndBack(): void
    {
        $this->assertSame(Outcome::Ok, Outcome::of(true));
        $this->assertSame(Outcome::Failed, Outcome::of(false));
        $this->assertTrue(Outcome::Ok->isOk());
        $this->assertFalse(Outcome::Failed->isOk());
    }
}
