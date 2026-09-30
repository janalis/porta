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

    public function testAnOffsetBecomesALineAndAByteColumn(): void
    {
        $text = "one\ntwo é\nthree";

        $this->assertSame([0, 0], Text::lineAndColumn($text, 0));
        $this->assertSame([0, 2], Text::lineAndColumn($text, 2));
        $this->assertSame([1, 0], Text::lineAndColumn($text, 4), 'just past a newline');
        $this->assertSame([2, 1], Text::lineAndColumn($text, (int) \strpos($text, needle: 'hree')), 'counted in bytes: é is two');
        $this->assertSame([2, 5], Text::lineAndColumn($text, 99), 'clamped to the end');
    }
}
