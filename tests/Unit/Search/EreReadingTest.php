<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Search\EreReading;

/** Whether grep -E reads a regex as PCRE does: what a grep without -P may be handed. */
final class EreReadingTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> a regex, and whether grep -E might read it otherwise than PCRE */
    public static function ereReadings(): iterable
    {
        foreach ([
            '\d+' => true,
            '[0-9]+' => false,
            '\w+' => false,
            '\bword\b' => false,
            'foo\.bar' => false,
            '\(?x' => false,
            '[\w.-]+@' => true,
            'a\tb' => true,
            '\<div' => true,
            '\Afoo' => true,
            '\Qa.b\E' => true,
            'foo\z' => true,
            '\n' => true,
            'C:\\\\dir' => false,
            'a.*?b' => true,
            'a++' => true,
            '(?=x)' => true,
            'a{,2}' => true,
            'a{1,2}' => false,
            '[]a]' => false,
            '[^]a]+' => false,
            '[[:alpha:]]+' => false,
            '\1' => true,
            // A `]` first in brackets (after a `^` too) is a member: what follows is still inside.
            '[]\w]' => true,
            '[^]\w]' => true,
            'a\\' => true,
        ] as $regex => $other) {
            yield $regex => [$regex, $other];
        }
    }

    /**
     * What grep -E is handed without -P is decided by an allow-list: an
     * escaped metacharacter, and `\w \W \s \S \b \B` outside brackets, are
     * read the same; anything else escaped, a backslash in brackets, `(?`, a
     * lazy or possessive quantifier, `{,n}` might not be.
     */
    #[DataProvider('ereReadings')]
    public function testWhatEreReadsTheSameIsAllowedAndNothingElse(string $regex, bool $other): void
    {
        $this->assertSame($other, EreReading::differs($regex));
    }
}
