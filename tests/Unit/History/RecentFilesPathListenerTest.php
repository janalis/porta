<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\Event\FileDeleted;
use Porta\History\RecentFilesPathListener;
use Porta\History\RecentFilesStore;
use Porta\Tests\Support\TempProject;

final class RecentFilesPathListenerTest extends TestCase
{
    public function testTheSwitcherStopsOfferingADeletedFile(): void
    {
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $store->record('/p/gone.php');
        $store->record('/p/kept.php');

        new RecentFilesPathListener($store)->onFileDeleted(new FileDeleted('/p/gone.php'));

        $this->assertSame(['/p/kept.php'], $store->all());
    }
}
