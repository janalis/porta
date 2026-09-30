<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Support\Text;

final class TextTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function firstLines(): iterable
    {
        yield 'one line' => ['dot: syntax error', 'dot: syntax error'];
        yield 'the first of several' => ["first\nsecond\nthird", 'first'];
        yield 'leading blank lines trimmed first' => ["\n\n  first\nsecond", 'first'];
        yield 'only the carriage return comes off the end' => ["first  \nsecond", 'first  '];
        yield 'a CRLF line loses its carriage return' => ["first\r\nsecond", 'first'];
        yield 'blank input' => ["  \n\t", ''];
    }

    #[DataProvider('firstLines')]
    public function testFirstLineIsTheTrimmedFirstLine(string $text, string $expected): void
    {
        $this->assertSame($expected, Text::firstLine($text));
    }
}
