<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\OccurrenceHighlights;

final class OccurrenceHighlightsTest extends TestCase
{
    /** @var list<array{0: int, 1: int, 2: int, 3: bool}> */
    private const array SERVER = [[1, 0, 6, true]];

    /** @var list<array{0: int, 1: int, 2: int, 3: bool}> */
    private const array SCAN = [[2, 5, 11, false]];

    public function testWithNothingPublishedTheScanIsWhatShows(): void
    {
        $highlights = new OccurrenceHighlights();

        $this->assertFalse($highlights->isFresh('/p/a.php', 1, 1, 2));
        $this->assertSame(self::SCAN, $highlights->spansFor('/p/a.php', 1, 1, 2, self::SCAN));
    }

    public function testAMatchingStampWinsOverTheScan(): void
    {
        $highlights = new OccurrenceHighlights();
        $highlights->publish('/p/a.php', 1, 1, 2, self::SERVER);

        $this->assertTrue($highlights->isFresh('/p/a.php', 1, 1, 2));
        $this->assertSame(self::SERVER, $highlights->spansFor('/p/a.php', 1, 1, 2, self::SCAN));
    }

    /**
     * Each part of the stamp has to matter on its own: a test that only varied
     * one would pass with the other three dropped from the key.
     *
     * @return \Generator<string, array{string, int, int, int}>
     */
    public static function nonMatchingAsks(): \Generator
    {
        yield 'another file' => ['/p/b.php', 1, 1, 2];
        yield 'the document has changed since' => ['/p/a.php', 2, 1, 2];
        yield 'the caret is on another line' => ['/p/a.php', 1, 9, 2];
        yield 'the caret is on another column' => ['/p/a.php', 1, 1, 9];
    }

    #[DataProvider('nonMatchingAsks')]
    public function testAStampForAnythingElseLosesToTheScan(string $path, int $version, int $line, int $col): void
    {
        $highlights = new OccurrenceHighlights();
        $highlights->publish('/p/a.php', 1, 1, 2, self::SERVER);

        $this->assertFalse($highlights->isFresh($path, $version, $line, $col));
        $this->assertSame(self::SCAN, $highlights->spansFor($path, $version, $line, $col, self::SCAN));
    }

    /** No file open at all: there is nothing an answer could be about. */
    public function testANullPathAlwaysFallsBackToTheScan(): void
    {
        $highlights = new OccurrenceHighlights();
        $highlights->publish('/p/a.php', 1, 1, 2, self::SERVER);

        $this->assertSame(self::SCAN, $highlights->spansFor(null, 1, 1, 2, self::SCAN));
    }

    /**
     * An EMPTY answer is still an answer — "this symbol occurs once here" — and
     * must suppress the scan rather than let it re-light same-named symbols
     * from other scopes.
     */
    public function testAnEmptyAnswerSuppressesTheScanRatherThanFallingBackToIt(): void
    {
        $highlights = new OccurrenceHighlights();
        $highlights->publish('/p/a.php', 1, 1, 2, []);

        $this->assertSame([], $highlights->spansFor('/p/a.php', 1, 1, 2, self::SCAN));
    }

    public function testTheCaretIsReportedMovedOncePerPosition(): void
    {
        $highlights = new OccurrenceHighlights();

        $this->assertTrue($highlights->caretMovedTo(1, 0, 0), 'the first position is a move');
        $this->assertFalse($highlights->caretMovedTo(1, 0, 0), 'a repaint at the same spot is not');
        $this->assertTrue($highlights->caretMovedTo(1, 0, 1));
        $this->assertTrue($highlights->caretMovedTo(1, 5, 1));
        $this->assertTrue($highlights->caretMovedTo(2, 5, 1), 'an edit at the same spot moves it too — the answer is version-stamped');
        $this->assertFalse($highlights->caretMovedTo(2, 5, 1));
    }
}
