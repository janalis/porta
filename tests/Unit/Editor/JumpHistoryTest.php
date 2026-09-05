<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\JumpHistory;

/**
 * The push/pop contract is exercised through the navigation controller; what
 * is pinned here is the pair the stack gained when it finally joined the other
 * path-keyed stores — and specifically the DIRECTORY case, which is the one an
 * exact-match implementation passes every other test without.
 */
final class JumpHistoryTest extends TestCase
{
    public function testRenamingADirectoryMovesEveryEntryInsideIt(): void
    {
        // Recent Locations lists this stack, so a folder rename left a screen
        // of rows that open nothing.
        $history = new JumpHistory();
        $history->push('/p/olden/b.php', 1, 0);
        $history->push('/p/old/deep/a.php', 2, 3);

        $history->rename('/p/old', '/p/new');

        $this->assertSame(
            [
                ['path' => '/p/new/deep/a.php', 'line' => 2, 'col' => 3],
                ['path' => '/p/olden/b.php', 'line' => 1, 'col' => 0],
            ],
            $history->entries(),
            'most recent first, and the prefix-sharing sibling untouched',
        );
    }

    public function testForgettingADirectoryDropsEveryEntryUnderIt(): void
    {
        $history = new JumpHistory();
        $history->push('/p/keep/z.php', 0, 0);
        $history->push('/p/old/a.php', 1, 0);
        $history->push('/p/olden/c.php', 2, 0);

        $history->forget('/p/old');

        $this->assertSame(
            [
                ['path' => '/p/olden/c.php', 'line' => 2, 'col' => 0],
                ['path' => '/p/keep/z.php', 'line' => 0, 'col' => 0],
            ],
            $history->entries(),
        );
    }
}
