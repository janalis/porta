<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\Decoded;

final class DecodedTest extends TestCase
{
    public function testOnlyTheObjectsOfADecodedListAreKeptWhereverTheyStand(): void
    {
        // A non-object FIRST, so a loop that stopped at it instead of stepping
        // over it would keep nothing.
        $this->assertSame(
            [['id' => 1], ['id' => 2]],
            Decoded::objects(['junk', ['id' => 1], 7, null, ['id' => 2]]),
        );
    }

    public function testAValueThatIsNoListAtAllHasNoObjects(): void
    {
        $this->assertSame([], Decoded::objects('a string'));
        $this->assertSame([], Decoded::objects(null));
        $this->assertSame([], Decoded::objects(3));
    }
}
