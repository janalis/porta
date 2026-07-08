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
                FuzzyMatcher::scoreLowered(strtolower($haystack), strtolower($query), $boundary, $haystack),
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
}
