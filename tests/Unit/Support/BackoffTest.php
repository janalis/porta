<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\Backoff;

final class BackoffTest extends TestCase
{
    public function testWaitsLongerAfterEachFailureUpToHalfAMinute(): void
    {
        $this->assertSame([1.0, 1.0, 2.0, 4.0, 8.0, 15.0, 30.0, 30.0], \array_map(Backoff::after(...), [-1, 0, 1, 2, 3, 4, 5, 99]));
    }
}
