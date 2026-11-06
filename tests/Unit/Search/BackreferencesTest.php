<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Search\Backreferences;

/**
 * The expansion is PHP's own, checked against `preg_replace()` itself: over a
 * whole match, preg_replace's output IS the expanded replacement, so any
 * difference is a reading of the grammar that PHP does not share.
 */
final class BackreferencesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function replacements(): iterable
    {
        yield 'dollar' => ['<$1>', '<a>'];
        yield 'backslash' => ['\\2\\1', 'ba'];
        yield 'braced, before a digit' => ['${1}0', 'a0'];
        yield 'two digits at most' => ['$123', '3'];
        yield 'past the last group' => ['[$9]', '[]'];
        yield 'escaped dollar' => ['\\$1', '$1'];
        yield 'escaped backslash' => ['\\\\1', '\\1'];
        yield 'unclosed brace' => ['${1', '${1'];
        yield 'not a reference' => ['$x\\y', '$x\\y'];
    }

    #[DataProvider('replacements')]
    public function testItExpandsAsPregReplaceDoes(string $replacement, string $expanded): void
    {
        \preg_match('/(a)(b)/', subject: 'ab', matches: $groups);

        $this->assertSame($expanded, Backreferences::expand($replacement, $groups));
        $this->assertSame((string) \preg_replace('/(a)(b)/', $replacement, subject: 'ab'), $expanded, 'the oracle agrees');
    }

    /** Every short string over the grammar's characters, with a group that took no part. */
    public function testItAgreesWithPregReplaceOverEveryShortReplacement(): void
    {
        $alphabet = ['\\', '$', '{', '}', '1', '2', '0', 'x'];
        $replacements = [''];
        for ($length = 1; $length <= 4; ++$length) {
            $longer = [];
            foreach ($replacements as $prefix) {
                foreach ($alphabet as $character) {
                    $longer[] = $prefix.$character;
                }
            }
            $replacements = $longer;
            foreach ($replacements as $replacement) {
                \preg_match('/(a)(z)?/', subject: 'a', matches: $groups);
                $this->assertSame((string) \preg_replace('/(a)(z)?/', $replacement, subject: 'a'), Backreferences::expand($replacement, $groups), \json_encode($replacement, \JSON_THROW_ON_ERROR));
            }
        }
    }
}
