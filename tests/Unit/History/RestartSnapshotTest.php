<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\OpenAs;
use Porta\Editor\PreviewMode;
use Porta\History\RestartSnapshot;
use Porta\Support\Files;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class RestartSnapshotTest extends TestCase
{
    public function testRoundTripKeepsUnsavedBytesAndDoesNotWriteProjectFiles(): void
    {
        $project = new TempProject(['a.php' => 'original', 'b.php' => 'second', 'image.png' => 'pixels']);
        $buffers = new BufferManager();
        $first = $buffers->open($project->path('a.php'), 'original');
        $first->document->reload("\xEF\xBB\xBFchanged\r\ntext");
        $first->document->setCursor(1, 2);
        $first->pinned = true;
        $buffers->open($project->path('b.php'), 'second');
        $buffers->openPane($project->path('image.png'), 'image', null);
        $buffers->setActive($first->path);
        $store = new RestartSnapshot();
        $directory = $project->path('handoff');
        $this->assertTrue($store->save($directory, $project->dir, $buffers));
        $restored = new BufferManager();
        $this->assertSame([], $store->restore($directory, $project->dir, $restored));
        $active = $restored->active();
        $this->assertInstanceOf(Buffer::class, $active);
        $this->assertSame("changed\ntext", $active->document->getText());
        $this->assertSame("\r\n", $active->document->eol);
        $this->assertTrue($active->document->hasByteOrderMark);
        $this->assertTrue($active->document->isDirty());
        $this->assertTrue($active->pinned);
        $this->assertSame([1, 2], [$active->document->cursorLine, $active->document->cursorCol]);
        $this->assertCount(3, $restored->all());
        $this->assertSame('original', \file_get_contents($first->path));
        $this->assertSame(0o700, \fileperms($directory) & 0o777);
        $this->assertSame(0o600, \fileperms($directory.'/session.json') & 0o777);
        $this->assertFalse($store->save($directory, $project->dir, $buffers), 'never overwrite another handoff');
    }

    public function testDiskConflictsAndDeletedDirtyFilesKeepUnsavedText(): void
    {
        $project = new TempProject(['a.php' => 'old']);
        $buffers = new BufferManager();
        $buffer = $buffers->open($project->path('a.php'), 'old');
        $buffer->document->replaceAll('unsaved');
        $store = new RestartSnapshot();
        $directory = $project->path('handoff');
        $this->assertTrue($store->save($directory, $project->dir, $buffers));
        \unlink($buffer->path);
        $restored = new BufferManager();
        $this->assertSame([$buffer->path], $store->restore($directory, $project->dir, $restored));
        $this->assertSame('unsaved', $restored->active()?->document->getText());
        $this->assertTrue($restored->active()?->document->isDirty());
        $this->assertFileExists($directory.'/session.json', 'retained until the caller acknowledges restoration');
    }

    public function testViewerRoutingAndPreviewArrangementAreRestored(): void
    {
        $project = new TempProject(['view.svg' => '<svg/>', 'notes.md' => 'notes']);
        $buffers = new BufferManager();
        $buffers->openPane($project->path('view.svg'), 'image', 'payload');
        $preview = $buffers->open($project->path('notes.md'), 'notes');
        $preview->previewId = 'markdown';
        $preview->previewMode = PreviewMode::Preview;
        $store = new RestartSnapshot();
        $directory = $project->path('handoff');
        $this->assertTrue($store->save($directory, $project->dir, $buffers));
        $restored = new BufferManager();
        $this->assertSame([], $store->restore($directory, $project->dir, $restored, static fn (string $path, OpenAs $as): Buffer => OpenAs::Viewer === $as
            ? $restored->openPane($path, 'image', 'new payload')
            : $restored->open($path, 'notes')));
        $this->assertTrue($restored->get($project->path('view.svg'))?->isViewer());
        $this->assertSame(PreviewMode::Preview, $restored->active()?->previewMode);
    }

    public function testMalformedHandoffIsRejectedBeforeAnyBufferOpens(): void
    {
        $project = new TempProject();
        $buffers = new BufferManager();
        $store = new RestartSnapshot();
        $this->assertNull($store->restore($project->dir, $project->dir, $buffers));
        foreach ([
            ['version' => 2],
            ['version' => 1, 'root' => Files::canonical($project->dir), 'directory' => Files::canonical($project->dir), 'buffers' => [['path' => 'a']]],
            [
                'version' => 1,
                'root' => Files::canonical($project->dir),
                'directory' => Files::canonical($project->dir),
                'buffers' => [['path' => '/a', 'bytes' => '!', 'dirty' => true, 'pinned' => false, 'line' => 0, 'col' => 0]],
            ],
        ] as $data) {
            $project->write('session.json', \json_encode($data, \JSON_THROW_ON_ERROR));
            $this->assertNull($store->restore($project->dir, $project->dir, $buffers));
            $this->assertSame([], $buffers->all());
        }
    }

    public function testEmptyRecoveredEditsStayDirtyAndAcknowledgementNeverDeletesUnrelatedFiles(): void
    {
        $project = new TempProject(['a.php' => 'old']);
        $buffers = new BufferManager();
        $buffers->open($project->path('a.php'), 'old')->document->replaceAll('');
        $store = new RestartSnapshot();
        $directory = $project->path('handoff');
        $this->assertTrue($store->save($directory, $project->dir, $buffers));
        \unlink($project->path('a.php'));
        $restored = new BufferManager();
        $this->assertSame([$project->path('a.php')], $store->restore($directory, $project->dir, $restored));
        $this->assertTrue($restored->active()?->document->isDirty());
        $project->write('handoff/unrelated.txt', 'keep');
        $this->assertFalse($store->acknowledge($directory));
        $this->assertSame('keep', \file_get_contents($directory.'/unrelated.txt'));
        \unlink($directory.'/unrelated.txt');
        $this->assertTrue($store->acknowledge($directory));
        $this->assertFalse($store->acknowledge($directory));
    }

    public function testFailedAcknowledgementKeepsTheSnapshot(): void
    {
        $project = new TempProject();
        $directory = $project->path('handoff');
        $this->assertTrue(new RestartSnapshot()->save($directory, $project->dir, new BufferManager()));
        $filesystem = new class extends Filesystem {
            public function remove(string|iterable $files): void
            {
                throw new IOException('not writable');
            }
        };
        $this->assertFalse(new RestartSnapshot($filesystem)->acknowledge($directory));
        $this->assertFileExists($directory.'/session.json');
    }

    public function testCleanTextDeletedDuringRestartIsRecoveredButMissingViewersAreReported(): void
    {
        $project = new TempProject(['text.php' => 'keep this text', 'image.png' => 'pixels']);
        $buffers = new BufferManager();
        $buffers->open($project->path('text.php'), 'keep this text');
        $buffers->openPane($project->path('image.png'), 'image', null);
        $store = new RestartSnapshot();
        $directory = $project->path('handoff');
        $this->assertTrue($store->save($directory, $project->dir, $buffers));
        \unlink($project->path('text.php'));
        \unlink($project->path('image.png'));
        $restored = new BufferManager();
        $this->assertSame([$project->path('text.php'), $project->path('image.png')], $store->restore($directory, $project->dir, $restored));
        $this->assertSame('keep this text', $restored->active()?->document->getText());
        $this->assertTrue($restored->active()?->document->isDirty());
        $this->assertCount(1, $restored->all());
    }
}
