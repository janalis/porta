<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Ui\BidiTui;

/**
 * The whole workbench in a terminal too small to hold its chrome.
 *
 * The menu bar, the main area and the status bar are three rows of fixed height,
 * and the vendor layout gives a flex:0 child its intrinsic height whatever the
 * terminal has — so the composed frame used to be TALLER than the screen at
 * small sizes: three rows into a one-row terminal, four into three. The extra
 * rows scroll the alternate screen, and every differential repaint after that
 * addresses rows one out, in a session that emits no `CSI H` or `CSI 2J` again
 * for as long as it runs.
 *
 * Driven through the real Ide rather than a rig widget because the three rows
 * are the real layout's, and a rig would have proved the clip against content a
 * test invented.
 */
final class TinyTerminalFrameTest extends TestCase
{
    use BootsIde;

    /** @return iterable<string, array{int, int}> */
    public static function geometries(): iterable
    {
        yield 'a sliver' => [1, 1];
        yield 'three rows' => [5, 3];
        yield 'four rows' => [10, 4];
        yield 'a usable window' => [80, 24];
    }

    #[DataProvider('geometries')]
    public function testTheFrameIsNeverTallerThanTheTerminal(int $columns, int $rows): void
    {
        $this->columns = $columns;
        $this->rows = $rows;
        $ide = $this->bootIde(['composer.json' => '{}', 'a.php' => "<?php\n\nfinal class A {}\n"]);
        $tui = Probe::object($ide, 'bidiTui');
        self::assertInstanceOf(BidiTui::class, $tui);

        $tui->requestRender(true);
        Probe::invoke($tui, 'processRender');

        // What Porta composed, kept for .porta/frame.txt — one entry per row.
        $frame = Probe::get($ide, 'lastFrame');
        self::assertIsArray($frame);
        self::assertLessThanOrEqual($rows, \count($frame), 'the composed frame fits the screen');
        self::assertNotSame([], $frame, 'and something was composed at all');
    }

    #[DataProvider('geometries')]
    public function testNoMoreRowsReachTheTerminalThanItHas(int $columns, int $rows): void
    {
        // The half that actually scrolls: what the writer emitted. Counted by its
        // row separators, because a row it writes is a row the terminal shows.
        $this->columns = $columns;
        $this->rows = $rows;
        $ide = $this->bootIde(['composer.json' => '{}', 'a.php' => "<?php\n\nfinal class A {}\n"]);
        $tui = Probe::object($ide, 'bidiTui');
        self::assertInstanceOf(BidiTui::class, $tui);

        $tui->requestRender(true);
        Probe::invoke($tui, 'processRender');

        $written = 1 + preg_match_all('/\r\n|\x1b\[\d*B/', $this->terminal->getOutput());
        self::assertLessThanOrEqual($rows, $written, 'one row past the last is enough to scroll the alternate screen for good');
    }
}
