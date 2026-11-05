<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\JsonKey;

final class JsonKeyTest extends TestCase
{
    public function testEqualValuesShareAKeyAndDifferentOnesDoNot(): void
    {
        $this->assertSame(JsonKey::of(['a' => 1, 'b' => [2]]), JsonKey::of(['a' => 1, 'b' => [2]]));
        $this->assertNotSame(JsonKey::of('1'), JsonKey::of(1), 'a string id and an int id are two registrations');
    }

    public function testAValueThatCannotBeEncodedIsTheEmptyKeyNotAThrow(): void
    {
        $this->assertSame('', JsonKey::of("\xB1"));
        $this->assertSame('', JsonKey::of(\NAN));
    }
}
