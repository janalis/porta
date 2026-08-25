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
}
