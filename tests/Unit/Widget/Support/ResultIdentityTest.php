<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Support;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Support\ResultIdentity;

/** The freeze/thaw drop rules the panel's capture/restore paths lean on. */
final class ResultIdentityTest extends TestCase
{
    public function testAStaleIndexAndAVanishedIdentityAreDroppedNotCrashedOn(): void
    {
        $results = [['path' => '/p/a.php', 'line' => 3, 'text' => 'x']];

        // Freeze: index 99 resolves to no result — the entry is dropped, in
        // both maps, and the live one keeps its identity key.
        [$expanded, $cache] = ResultIdentity::freezeContext($results, [0 => true, 99 => true], [0 => [['line' => 3, 'text' => 'x']], 99 => [['line' => 9, 'text' => 'gone']]]);
        $key = "/p/a.php\x003";
        $this->assertSame([$key => true], $expanded, 'the stale expanded index froze to nothing');
        $this->assertSame([$key => [['line' => 3, 'text' => 'x']]], $cache, 'the stale cache index froze to nothing');

        // Thaw against a fresh row set that no longer contains the identity:
        // both maps come back empty rather than pointing at a wrong row.
        [$thawedExpanded, $thawedCache] = ResultIdentity::thawContext([['path' => '/p/other.php', 'line' => 1, 'text' => 'y']], $expanded, $cache);
        $this->assertSame([], $thawedExpanded);
        $this->assertSame([], $thawedCache);
    }
}
