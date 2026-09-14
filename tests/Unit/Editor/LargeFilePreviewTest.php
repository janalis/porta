<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\TextPreview;
use Porta\Support\Files;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\Toasts;
use Porta\Widget\TextPreviewWidget;

/**
 * A file over the edit cap opens READ-ONLY instead of being refused.
 *
 * It used to flash "File too large (> 2 MB)" and stop there, so a
 * composer.lock that had grown, a log, a dump or a bundled asset was simply
 * unopenable — the answer was to leave the IDE and look at it somewhere else.
 *
 * Written as one case over one fixture on purpose: the file has to be built
 * past a two-megabyte cap to reach the branch at all, and doing that twice to
 * ask two questions about the same open is two megabytes of disk per question.
 */
final class LargeFilePreviewTest extends TestCase
{
    use BootsIde;

    public function testItOpensAsABoundedReadOnlyPreviewInsteadOfBeingRefused(): void
    {
        $ide = $this->bootIde(['composer.json' => '{}']);
        $path = $this->project->path('huge.log');
        $line = "a line of log text\n";
        // One line PAST the cap: the branch is `>`, and a fixture sitting exactly
        // on the boundary would pass whichever way the comparison went.
        \file_put_contents($path, \str_repeat($line, times: 1 + \intdiv(Files::MAX_TEXT_BYTES, \strlen($line))));
        $size = (int) \filesize($path);
        $this->assertGreaterThan(Files::MAX_TEXT_BYTES, $size);

        Services::resolve($ide, BufferService::class)->openFile($path);

        $buffer = Services::resolve($ide, BufferManager::class)->active();
        $this->assertInstanceOf(Buffer::class, $buffer);
        // realpath: the IDE canonicalises on open, and macOS resolves the temp
        // directory's /var symlink to /private/var.
        $this->assertSame(\realpath($path), $buffer->path, 'the file opened');
        $this->assertSame([], Toasts::all($ide), 'and nothing was refused');

        // The read-only guarantee is the buffer's SHAPE, not a flag somebody has
        // to remember to check: a pane buffer carries a permanently-empty
        // document that is never dirty.
        $this->assertSame(TextPreviewWidget::PANE_ID, $buffer->paneId, 'it opened in the preview pane, not the editor');
        $this->assertSame('', $buffer->document->getText());
        $this->assertFalse($buffer->document->isDirty());

        $preview = $buffer->panePayload;
        $this->assertInstanceOf(TextPreview::class, $preview);
        $this->assertTrue($preview->truncated);
        $this->assertSame($size, $preview->bytes, "the size reported is the FILE's, not the prefix's");
        $this->assertLessThanOrEqual(Files::MAX_TEXT_BYTES, \strlen($preview->text), 'never more than one cap is read');
        $this->assertStringEndsWith('a line of log text', $preview->text, 'cut at a line break, not mid-line');
    }

    /**
     * A huge file that is not text is still REFUSED — the read-only preview is
     * a way to LOOK at a large file, not a way to render one as mojibake.
     *
     * The refusal comes from the pane's own loader throwing, which is the one
     * place a pane gets to say "not this file" after its claim already said
     * "mine" — the same shape the help pane uses for a binary docs page.
     */
    public function testAHugeFileThatIsNotUtf8IsStillRefused(): void
    {
        $ide = $this->bootIde(['composer.json' => '{}']);
        $path = $this->project->path('huge.bin');
        // Invalid UTF-8 in the FIRST bytes, so the prefix carries it whatever
        // the cut does; padded past the cap to reach the preview branch at all.
        \file_put_contents($path, "\xff\xfe\x00".\str_repeat("binary\n", times: 1 + \intdiv(Files::MAX_TEXT_BYTES, num2: 7)));
        $this->assertGreaterThan(Files::MAX_TEXT_BYTES, (int) \filesize($path));

        Services::resolve($ide, BufferService::class)->openFile($path);

        $this->assertNull(Services::resolve($ide, BufferManager::class)->active(), 'no tab opened');
        $this->assertStringContainsString('Not a UTF-8 text file', Toasts::last($ide));
    }

    /**
     * A cap's worth with no line break in it at all — a minified bundle is
     * megabytes on ONE line — keeps the raw prefix.
     *
     * There is nothing better to cut at, and the alternative to keeping it is
     * showing an empty pane for a file the user asked to see. The other case
     * above only exercises the cut; this is the branch where there is none.
     */
    public function testAFileWithNoLineBreakKeepsTheWholePrefix(): void
    {
        $ide = $this->bootIde(['composer.json' => '{}']);
        $path = $this->project->path('bundle.min.js');
        \file_put_contents($path, \str_repeat('x', times: Files::MAX_TEXT_BYTES + 512));

        Services::resolve($ide, BufferService::class)->openFile($path);

        $buffer = Services::resolve($ide, BufferManager::class)->active();
        $this->assertInstanceOf(Buffer::class, $buffer);
        $preview = $buffer->panePayload;
        $this->assertInstanceOf(TextPreview::class, $preview);
        $this->assertSame(Files::MAX_TEXT_BYTES, \strlen($preview->text), 'the whole prefix, uncut');
        $this->assertTrue($preview->truncated);
    }
}
