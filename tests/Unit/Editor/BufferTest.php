<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\Document;
use Porta\Highlight\CodeHighlighter;
use Porta\Tests\Support\TempProject;

final class BufferTest extends TestCase
{
    public function testTheInjectedHighlighterIsUsedNotAFreshDefaultOne(): void
    {
        $highlighter = new CodeHighlighter();
        $buffer = new Buffer('/p/a.php', new Document(''), $highlighter);

        $this->assertSame($highlighter, $buffer->highlighter);
    }

    public function testConstructionPointsTheHighlighterAtTheBuffersFile(): void
    {
        // Buffer must call setFile() itself — a highlighter that never learns
        // its file never learns its language, and highlights nothing.
        $buffer = new Buffer('/p/a.php', new Document(''));

        $this->assertSame('php', $buffer->highlighter->getLanguage());
    }

    public function testDiskStampNowIsTheMtimeAndSizeJoinedByAColon(): void
    {
        $project = new TempProject(['a.php' => 'hello']);
        $path = $project->path('a.php');
        $buffer = new Buffer($path, new Document(''));

        $expected = (int) filemtime($path).':'.(int) filesize($path);
        $this->assertSame($expected, $buffer->diskStampNow());
        $this->assertStringContainsString(':', $buffer->diskStampNow() ?? '');
        $this->assertSame((string) filesize($path), explode(':', (string) $buffer->diskStampNow())[1]);
    }

    public function testDiskStampNowIsNullForAPathThatIsNotAFile(): void
    {
        $buffer = new Buffer('/no/such/file.php', new Document(''));

        $this->assertNull($buffer->diskStampNow());
    }

    public function testDiskStampNowSeesAnExternalRewriteImmediately(): void
    {
        // The poll no longer calls clearstatcache() each second: external-edit
        // detection relies on consecutive stat calls seeing fresh values (true
        // on the target runtime — this guards that assumption with a write the
        // PHP process itself never made).
        $project = new TempProject(['a.php' => 'one']);
        $path = $project->path('a.php');
        $buffer = new Buffer($path, new Document(''));
        $before = $buffer->diskStampNow(); // any cached stat would date from here

        exec('printf "a longer rewrite" > '.escapeshellarg($path));

        $this->assertNotSame($before, $buffer->diskStampNow(), 'the external write is visible immediately');
        $this->assertSame((string) \strlen('a longer rewrite'), explode(':', (string) $buffer->diskStampNow())[1]);
    }

    public function testStampFromDiskRecordsTheCurrentFingerprint(): void
    {
        $project = new TempProject(['a.php' => 'hello']);
        $path = $project->path('a.php');
        $buffer = new Buffer($path, new Document(''));

        $buffer->stampFromDisk();

        $this->assertSame($buffer->diskStampNow(), $buffer->diskStamp);
    }

    public function testTheDiskStampSeesAChangeMadeByANOTHERProcess(): void
    {
        // The whole point of the stamp is to notice an edit Porta did not make.
        // PHP caches stat results per path for the life of the process and
        // clears that cache only for writes IT performs -- so a stamp taken
        // before an external edit keeps answering with the old one, and the
        // reconciler polls forever without seeing anything.
        //
        // Every existing test wrote the file IN-PROCESS, which clears the cache
        // as a side effect and hides this completely. The writer here is a
        // separate process, which is the case that actually happens: another
        // editor, a formatter, git checkout.
        $project = new TempProject(['a.php' => "one\n"]);
        $path = $project->path('a.php');
        $buffer = new Buffer($path, new Document(''));

        $before = $buffer->diskStampNow();
        $this->assertNotNull($before);

        $proc = proc_open(['/bin/sh', '-c', 'sleep 1; printf "changed-externally\n" > '.escapeshellarg($path)], [], $pipes);
        \assert(\is_resource($proc), description: 'the external writer started');
        try {
            $after = $before;
            for ($i = 0; $i < 30 && $after === $before; ++$i) {
                usleep(100_000);
                $after = $buffer->diskStampNow();
            }
        } finally {
            proc_close($proc);
        }

        $this->assertNotSame($before, $after, 'an edit by another process is never noticed');
    }
}
