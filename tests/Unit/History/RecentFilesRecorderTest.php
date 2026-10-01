<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\Document;
use Porta\Event\BufferSwitched;
use Porta\History\RecentFilesRecorder;
use Porta\History\RecentFilesStore;
use Porta\Tests\Support\TempProject;

final class RecentFilesRecorderTest extends TestCase
{
    public function testEveryEditorBufferShownIsRecorded(): void
    {
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $recorder = new RecentFilesRecorder($store);

        $recorder->onBufferSwitched(new BufferSwitched(new Buffer('/a.php', new Document(''))));
        $recorder->onBufferSwitched(new BufferSwitched(new Buffer('/b.php', new Document(''))));

        $this->assertSame(['/b.php', '/a.php'], $store->paths);
    }

    public function testAVIEWERTabIsNot(): void
    {
        // The trap SessionRestorer sidesteps on save: a help page's path would
        // come back as an offer to reopen the docs as editable source, and an
        // image has no text to switch to at all.
        $project = new TempProject();
        $store = new RecentFilesStore($project->dir);
        $recorder = new RecentFilesRecorder($store);

        $recorder->onBufferSwitched(new BufferSwitched(new Buffer('/docs/editor.md', new Document(''), paneId: 'markdown')));
        $recorder->onBufferSwitched(new BufferSwitched(new Buffer('/logo.png', new Document(''), paneId: 'image')));

        $this->assertSame([], $store->paths);
    }
}
