<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\JumpHistory;
use Porta\Editor\JumpHistoryPathListener;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;

final class JumpHistoryPathListenerTest extends TestCase
{
    public function testNavigateBackFollowsARenamedFile(): void
    {
        $history = new JumpHistory();
        $history->push('/p/moved.php', 4, 2);
        $listener = new JumpHistoryPathListener($history);

        $listener->onFileRenamed(new FileRenamed('/p/moved.php', '/p/new.php'));

        $this->assertSame(['path' => '/p/new.php', 'line' => 4, 'col' => 2], $history->pop(), 'the line and column came with it');
    }

    public function testNavigateBackNeverLandsInADeletedFile(): void
    {
        $history = new JumpHistory();
        $history->push('/p/kept.php', 1, 0);
        $history->push('/p/gone.php', 2, 0);

        new JumpHistoryPathListener($history)->onFileDeleted(new FileDeleted('/p/gone.php'));

        $this->assertSame(['path' => '/p/kept.php', 'line' => 1, 'col' => 0], $history->pop());
        $this->assertNull($history->pop(), 'and nothing behind it');
    }
}
