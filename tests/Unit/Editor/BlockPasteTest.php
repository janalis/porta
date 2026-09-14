<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Caret;
use Porta\Editor\Document;
use Porta\Editor\MultiCaret;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Block copy and paste have to ROUND-TRIP.
 *
 * `selectedBlockText()` joins each caret's selection with newlines, so copying
 * a three-row rectangle yields three lines — and pasting all three at each of
 * three carets turned nine lines out of three. Every assertion here names the
 * resulting BUFFER: the failing case produced the right number of pastes and
 * the wrong number of lines, so a test that counted carets passed either way.
 */
final class BlockPasteTest extends TestCase
{
    private static function noop(): \Closure
    {
        return static function (): void {};
    }

    private static function keys(): Keybindings
    {
        // A plain character, so the test exercises the DISTRIBUTION rather
        // than the key parser's view of ctrl+v.
        return new Keybindings(['paste' => ['p']]);
    }

    /** Three carets, one per line, at column $col. */
    private static function caretsDown(Document $doc, MultiCaret $mc, int $col, int ...$lines): void
    {
        $doc->setCursor($lines[0], $col);
        $extras = [];
        foreach (\array_slice($lines, offset: 1) as $line) {
            $extras[] = Caret::point($line, $col);
        }
        $mc->setExtras($extras);
    }

    private static function paste(MultiCaret $mc, string $clipboard): void
    {
        $mc->handleInput('p', self::keys(), $clipboard, static fn (string $k): bool => false);
    }

    public function testAThreeLineClipboardGoesONELineToEachOfThreeCarets(): void
    {
        $doc = new Document("a\nb\nc\n");
        $mc = new MultiCaret($doc, self::noop(), self::noop());
        self::caretsDown($doc, $mc, 1, 0, 1, 2);

        self::paste($mc, "X\nY\nZ");

        $this->assertSame("aX\nbY\ncZ\n", $doc->getText());
    }

    public function testTheTOPLineGoesToTheTOPCaret(): void
    {
        // The walk runs bottom-to-top for safety; the block reads top-to-bottom.
        // Reversing them silently would still paste three lines into three
        // carets, which is why this asserts WHICH line landed where.
        $doc = new Document("a\nb\nc\n");
        $mc = new MultiCaret($doc, self::noop(), self::noop());
        self::caretsDown($doc, $mc, 1, 0, 1, 2);

        self::paste($mc, "1\n2\n3");

        $this->assertSame("a1\nb2\nc3\n", $doc->getText());
    }

    public function testCaretsCreATEDOutOfOrderStillReceiveTheBlockTopDown(): void
    {
        // The primary is on the LAST line here, so caret creation order and
        // reading order disagree.
        $doc = new Document("a\nb\nc\n");
        $mc = new MultiCaret($doc, self::noop(), self::noop());
        $doc->setCursor(2, 1);
        $mc->setExtras([Caret::point(0, 1), Caret::point(1, 1)]);

        self::paste($mc, "1\n2\n3");

        $this->assertSame("a1\nb2\nc3\n", $doc->getText());
    }

    public function testAMISMATCHEDClipboardIsRepeatedWholeAtEveryCaret(): void
    {
        // Two lines across three carets has no correct distribution, and
        // guessing one is worse than repeating something predictable.
        $doc = new Document("a\nb\nc\n");
        $mc = new MultiCaret($doc, self::noop(), self::noop());
        self::caretsDown($doc, $mc, 1, 0, 1, 2);

        self::paste($mc, "X\nY");

        $this->assertSame("aX\nY\nbX\nY\ncX\nY\n", $doc->getText());
    }

    public function testASINGLELineClipboardStillGoesToEveryCaret(): void
    {
        // One line and one caret would "match", but repeating a single line at
        // every caret is the useful behaviour and the one people rely on.
        $doc = new Document("a\nb\nc\n");
        $mc = new MultiCaret($doc, self::noop(), self::noop());
        self::caretsDown($doc, $mc, 1, 0, 1, 2);

        self::paste($mc, 'X');

        $this->assertSame("aX\nbX\ncX\n", $doc->getText());
    }

    public function testABlockSurvivesACopyAndPasteUNCHANGED(): void
    {
        // The round trip itself, through the real copy verb.
        $doc = new Document("12\n34\n56\n");
        $mc = new MultiCaret($doc, self::noop(), self::noop());
        $doc->setSelectionRange(0, 0, 0, 1);
        $mc->setExtras([new Caret(line: 1, col: 1, anchorLine: 1, anchorCol: 0), new Caret(line: 2, col: 1, anchorLine: 2, anchorCol: 0)]);

        $copied = $mc->selectedBlockText();
        $this->assertSame("1\n3\n5", $copied, 'a column copy is one line per caret');

        // Paste it into the same rectangle: each cell gets its own line back.
        self::paste($mc, $copied);

        $this->assertSame("12\n34\n56\n", $doc->getText());
    }
}
