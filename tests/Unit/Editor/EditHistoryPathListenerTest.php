<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\EditHistory;
use Porta\Editor\EditHistoryPathListener;
use Porta\Event\FileDeleted;

final class EditHistoryPathListenerTest extends TestCase
{
    public function testAWalkBackNeverLandsInADeletedFile(): void
    {
        $history = new EditHistory();
        $history->record('/p/kept.php', 2);
        $history->record('/p/gone.php', 7);

        new EditHistoryPathListener($history)->onFileDeleted(new FileDeleted('/p/gone.php'));

        $this->assertSame(['path' => '/p/kept.php', 'line' => 2], $history->walkBack());
    }
}
