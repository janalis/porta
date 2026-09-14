<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\ServerProgress;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\StatusBarProvider;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusMenuSpan;
use Porta\Workbench\StatusSegment;
use Porta\Workbench\StatusTone;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The left prefix and the column ranges that make its cells right-clickable.
 *
 * Worth its own test because the span arithmetic is the kind that a screen
 * assertion cannot see: every offset is derived from the VISIBLE width of what
 * came before, so an off-by-one only shows as a right-click landing on the
 * neighbouring cell's menu. Nothing else drives {@see StatusBarProvider} with
 * more than one contributor.
 */
final class StatusBarPrefixTest extends TestCase
{
    /** @param list<list<StatusCell>> $perSource */
    private function provider(array $perSource): StatusBarProvider
    {
        $sources = \array_map(
            static fn (array $cells): StatusSegment => new readonly class($cells) implements StatusSegment {
                /** @param list<StatusCell> $cells */
                public function __construct(
                    private array $cells,
                ) {}

                public function statusCells(): array
                {
                    return $this->cells;
                }
            },
            $perSource,
        );

        return new StatusBarProvider(
            new BufferManager(),
            new CodeEditorWidget(),
            new EditorPanes(),
            $sources,
            new LspSessionPool(\sys_get_temp_dir(), new FixedActiveFile(), new Translator('en')),
            new ServerProgress(new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20))),
            Styles::dark(),
            new Translator('en'),
            new ProjectPaths(\sys_get_temp_dir()),
        );
    }

    public function testACellsMenuSpanCoversExactlyItsOwnColumns(): void
    {
        $segments = $this->provider([[new StatusCell('⎇ main', StatusTone::Accent, static fn (): array => [])]])->segments();

        $this->assertCount(1, $segments['menus']);
        $span = $segments['menus'][0];
        $this->assertInstanceOf(StatusMenuSpan::class, $span);
        $this->assertSame(0, $span->from, 'the first cell starts at column 0');
        $this->assertSame(AnsiUtils::visibleWidth('⎇ main'), $span->to, 'and ends at its visible width, not its byte length');
    }

    public function testALaterCellsSpanShiftsRightByWhatPrecedesItSeparatorsIncluded(): void
    {
        // The failure mode: a menu that opens one cell to the left. Every offset
        // is a running sum, so only a second contributor exercises it.
        $segments = $this->provider([
            [new StatusCell('⏸ prog.php:3', StatusTone::Active)],
            [new StatusCell('⎇ main', StatusTone::Accent, static fn (): array => [])],
        ])->segments();

        $this->assertCount(1, $segments['menus'], 'only the clickable cell contributes a span');
        $expected = AnsiUtils::visibleWidth('⏸ prog.php:3') + 2; // the two-column separator after it
        $this->assertSame($expected, $segments['menus'][0]->from);
    }

    public function testATextLessCellReservesNoColumnsAtAll(): void
    {
        // Not the same as contributing nothing: an empty cell still went through
        // the loop, and painting it would cost the two-column separator after
        // it — shifting every following cell's menu span off its own text.
        $segments = $this->provider([
            [new StatusCell('')],
            [new StatusCell('⎇ main', StatusTone::Accent, static fn (): array => [])],
        ])->segments();

        $this->assertSame(0, $segments['menus'][0]->from, 'the branch is still the first thing painted');
    }

    public function testOneSourceMayContributeSeveralCellsEachWithItsOwnSpan(): void
    {
        // What the plugin slot does: it speaks for every plugin at once, so its
        // cells must be laid out as peers rather than as one run of text.
        $menu = static fn (): array => [];
        $segments = $this->provider([[
            new StatusCell('▶ build'),
            new StatusCell('⎇ main', StatusTone::Accent, $menu),
        ]])->segments();

        $this->assertCount(1, $segments['menus']);
        $this->assertSame(AnsiUtils::visibleWidth('▶ build') + 2, $segments['menus'][0]->from);
    }
}
