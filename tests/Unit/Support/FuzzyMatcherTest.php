<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\FuzzyMatcher;

final class FuzzyMatcherTest extends TestCase
{
    public function testNonSubsequenceReturnsNull(): void
    {
        $this->assertNull(FuzzyMatcher::score('foobar', 'xyz'));
        // Right letters, wrong order is still not a subsequence.
        $this->assertNull(FuzzyMatcher::score('abc', 'ba'));
    }

    public function testCaseInsensitiveSubsequenceMatches(): void
    {
        $this->assertNotNull(FuzzyMatcher::score('getMessage', 'GM'));
        $this->assertNotNull(FuzzyMatcher::score('str_replace', 'srp'));
    }

    public function testConsecutiveRunScoresHigherThanScattered(): void
    {
        $consecutive = FuzzyMatcher::score('foobar', 'foo');
        $scattered = FuzzyMatcher::score('fxoxox', 'foo');
        $this->assertNotNull($consecutive);
        $this->assertNotNull($scattered);
        $this->assertGreaterThan($scattered, $consecutive);
    }

    public function testCamelHumpBeatsMidWordMatch(): void
    {
        // "gm" as camelCase humps in getMessage should outrank the same letters
        // buried mid-word in "programmer".
        $hump = FuzzyMatcher::score('getMessage', 'gm');
        $buried = FuzzyMatcher::score('programmer', 'gm');
        $this->assertNotNull($hump);
        $this->assertNotNull($buried);
        $this->assertGreaterThan($buried, $hump);
    }

    public function testBoundaryStartBonusRewardsPrimarySegment(): void
    {
        // Same letters, but with the primary segment starting at offset 4 the
        // match inside it scores higher.
        $withBonus = FuzzyMatcher::score('dir/name', 'name', 4);
        $without = FuzzyMatcher::score('dir/name', 'name', 100);
        $this->assertGreaterThan($without, $withBonus);
    }

    public function testScoreLoweredMatchesScoreOnPreLoweredInputs(): void
    {
        foreach ([
            ['src/UserRepo.php', 'urp',  4],
            ['getUserName',      'gun',  0],
            ['a-b_c.d',          'abcd', 0],
        ] as [$haystack, $query, $boundary]) {
            $this->assertSame(
                FuzzyMatcher::score($haystack, $query, $boundary),
                FuzzyMatcher::scoreLowered(\strtolower($haystack), \strtolower($query), $boundary, $haystack),
                "parity for {$haystack}",
            );
        }
    }

    public function testScoreLoweredWithoutOriginalDisablesHumps(): void
    {
        // The hump bonus needs the original case; without it the same match
        // scores lower — exactly the go-to-file palette's deliberate behavior.
        $withHumps = FuzzyMatcher::scoreLowered('getusername', 'un', 0, 'getUserName');
        $withoutHumps = FuzzyMatcher::scoreLowered('getusername', 'un', 0);
        $this->assertNotNull($withoutHumps);
        $this->assertGreaterThan($withoutHumps, $withHumps);
        $this->assertNull(FuzzyMatcher::scoreLowered('abc', 'xyz'), 'non-subsequence still null');
    }

    /** The query as one run where it occurs whole; else the scored greedy subsequence; else nothing. */
    public function testPositionsPreferAWholeRunThenTheSubsequence(): void
    {
        $this->assertSame([6, 7, 8], FuzzyMatcher::positions('Hello World', 'WOR'), 'a whole run, case-insensitively');
        $this->assertSame([0, 7, 18], FuzzyMatcher::positions('ExampleController.php', 'ecp'), 'the first occurrence of each, in order');
        $this->assertSame([], FuzzyMatcher::positions('abc', 'z'));
        $this->assertSame([], FuzzyMatcher::positions('abc', 'ca'), 'out of order is no match');
        $this->assertSame([], FuzzyMatcher::positions('abc', ''));
        $this->assertSame([1, 2], FuzzyMatcher::positions('éÉè', 'éè'), 'by character, not by byte');
    }

    /** Every letter is folded, not ASCII alone: é finds the É of a name — and a name whose lowercase is longer keeps its score sane. */
    public function testEveryLetterFolds(): void
    {
        $this->assertNotNull(FuzzyMatcher::score('ÉtéReport', 'été'));
        $this->assertNotNull(FuzzyMatcher::score('étéReport', 'ÉTÉ'), 'the query folds as well as the name');
        $this->assertNotNull(FuzzyMatcher::score('İstanbulSeries', 'series'), 'İ lowers to two code points: the hump bonus is skipped, the match stands');
    }

    /** A capital right after a lowercase letter is a hump, read from the letter just before it. */
    public function testAHumpIsACapitalRightAfterALowercaseLetter(): void
    {
        $this->assertGreaterThan(FuzzyMatcher::score('Xabc', 'b'), FuzzyMatcher::score('XaBc', 'b'));
    }
}
