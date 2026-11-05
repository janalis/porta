<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\Decoded;

final class DecodedTest extends TestCase
{
    public function testJsonIsTheObjectOrListTheTextHoldsAndNullForAnythingElse(): void
    {
        $this->assertSame(['type' => 'hello'], Decoded::json('{"type":"hello"}'));
        $this->assertSame([1, 2], Decoded::json('[1,2]'));
        $this->assertNull(Decoded::json('not json'), 'a peer\'s garbage reads as nothing, it does not throw');
        $this->assertNull(Decoded::json('"a bare string"'));
        $this->assertNull(Decoded::json(''));
    }

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
