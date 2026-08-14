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
        // both maps. The stale index comes FIRST so a drop that stopped the
        // walk (a continue degraded to a break) would strand the live entry.
        [$expanded, $cache] = ResultIdentity::freezeContext($results, [99 => true, 0 => true], [99 => [['line' => 9, 'text' => 'gone']], 0 => [['line' => 3, 'text' => 'x']]]);
        $key = "/p/a.php\x003";
        $this->assertSame([$key => true], $expanded, 'the stale expanded index froze to nothing, the live one survived it');
        $this->assertSame([$key => [['line' => 3, 'text' => 'x']]], $cache, 'the stale cache index froze to nothing, the live one survived it');

        // Thaw with a vanished identity FIRST for the same reason; the one
        // still present maps back to its fresh position.
        [$thawedExpanded, $thawedCache] = ResultIdentity::thawContext(
            [['path' => '/p/other.php', 'line' => 1, 'text' => 'y'], ['path' => '/p/a.php', 'line' => 3, 'text' => 'x']],
            ["/p/gone.php\x001" => true, $key => true],
            ["/p/gone.php\x001" => [['line' => 1, 'text' => 'g']], $key => [['line' => 3, 'text' => 'x']]],
        );
        $this->assertSame([1 => true], $thawedExpanded, 'the vanished identity thawed to nothing, the live one to its new row');
        $this->assertSame([1 => [['line' => 3, 'text' => 'x']]], $thawedCache);
    }
}
