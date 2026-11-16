<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\FuzzyMatcher;

final class FuzzyMatcherTest extends TestCase
{
    public function testNonSubsequenceReturnsNull(): void
    {
        $this->assertNull(FuzzyMatcher::score('foobar', query: 'xyz'));
        // Right letters, wrong order is still not a subsequence.
        $this->assertNull(FuzzyMatcher::score('abc', query: 'ba'));
    }

    public function testCaseInsensitiveSubsequenceMatches(): void
    {
        $this->assertNotNull(FuzzyMatcher::score('getMessage', query: 'GM'));
        $this->assertNotNull(FuzzyMatcher::score('str_replace', query: 'srp'));
    }

    public function testConsecutiveRunScoresHigherThanScattered(): void
    {
        $consecutive = FuzzyMatcher::score('foobar', query: 'foo');
        $scattered = FuzzyMatcher::score('fxoxox', query: 'foo');
        $this->assertNotNull($consecutive);
        $this->assertNotNull($scattered);
        $this->assertGreaterThan($scattered, $consecutive);
    }

    public function testCamelHumpBeatsMidWordMatch(): void
    {
        // "gm" as camelCase humps in getMessage should outrank the same letters
        // buried mid-word in "programmer".
        $hump = FuzzyMatcher::score('getMessage', query: 'gm');
        $buried = FuzzyMatcher::score('programmer', query: 'gm');
        $this->assertNotNull($hump);
        $this->assertNotNull($buried);
        $this->assertGreaterThan($buried, $hump);
    }

    public function testBoundaryStartBonusRewardsPrimarySegment(): void
    {
        // Same letters, but with the primary segment starting at offset 4 the
        // match inside it scores higher.
        $withBonus = FuzzyMatcher::score('dir/name', query: 'name', boundaryStart: 4);
        $without = FuzzyMatcher::score('dir/name', query: 'name', boundaryStart: 100);
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
        $withHumps = FuzzyMatcher::scoreLowered('getusername', lowerQuery: 'un', boundaryStart: 0, original: 'getUserName');
        $withoutHumps = FuzzyMatcher::scoreLowered('getusername', lowerQuery: 'un', boundaryStart: 0);
        $this->assertNotNull($withoutHumps);
        $this->assertGreaterThan($withoutHumps, $withHumps);
        $this->assertNull(FuzzyMatcher::scoreLowered('abc', lowerQuery: 'xyz'), 'non-subsequence still null');
    }

    /** The query as one run where it occurs whole; else the scored greedy subsequence; else nothing. */
    public function testPositionsPreferAWholeRunThenTheSubsequence(): void
    {
        $this->assertSame([6, 7, 8], FuzzyMatcher::positions('Hello World', query: 'WOR'), 'a whole run, case-insensitively');
        $this->assertSame([0, 7, 18], FuzzyMatcher::positions('ExampleController.php', query: 'ecp'), 'the first occurrence of each, in order');
        $this->assertSame([], FuzzyMatcher::positions('abc', query: 'z'));
        $this->assertSame([], FuzzyMatcher::positions('abc', query: 'ca'), 'out of order is no match');
        $this->assertSame([], FuzzyMatcher::positions('abc', query: ''));
        $this->assertSame([1, 2], FuzzyMatcher::positions('éÉè', query: 'éè'), 'by character, not by byte');
    }

    /** Every letter is folded, not ASCII alone: é finds the É of a name — and a name whose lowercase is longer keeps its score sane. */
    public function testEveryLetterFolds(): void
    {
        $this->assertNotNull(FuzzyMatcher::score('ÉtéReport', query: 'été'));
        $this->assertNotNull(FuzzyMatcher::score('étéReport', query: 'ÉTÉ'), 'the query folds as well as the name');
        $this->assertNotNull(FuzzyMatcher::score('İstanbulSeries', query: 'series'), 'İ lowers to two code points: the hump bonus is skipped, the match stands');
    }

    /** A capital right after a lowercase letter is a hump, read from the letter just before it. */
    public function testAHumpIsACapitalRightAfterALowercaseLetter(): void
    {
        $this->assertGreaterThan(FuzzyMatcher::score('Xabc', query: 'b'), FuzzyMatcher::score('XaBc', query: 'b'));
    }
}
