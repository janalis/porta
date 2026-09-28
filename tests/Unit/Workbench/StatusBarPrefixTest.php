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
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\StatusBarProvider;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusSegment;
use Porta\Workbench\StatusTone;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The left's cells and the column ranges that make them clickable, from the
 * contributors through the provider to the columns the bar draws them at.
 *
 * Worth its own test because the span arithmetic is the kind that a screen
 * assertion cannot see: every offset is derived from the VISIBLE width of what
 * came before, so an off-by-one only shows as a click landing on the
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
            new Keybindings([]),
        );
    }

    /**
     * The bar the provider feeds, rendered: the spans exist only once the bar
     * has laid the cells out.
     *
     * @param list<list<StatusCell>> $perSource
     */
    private function bar(array $perSource): StatusBarWidget
    {
        $provider = $this->provider($perSource);
        $bar = new StatusBarWidget();
        $bar->setProvider($provider->segments(...));
        $bar->beforeRender();
        $bar->render(new RenderContext(120, 1));

        return $bar;
    }

    /** @return list<int> the columns up to $upTo that a menu answers at */
    private static function menuColumns(StatusBarWidget $bar, int $upTo): array
    {
        return \array_values(\array_filter(\range(0, $upTo), static fn (int $col): bool => null !== $bar->menuAt($col)));
    }

    public function testACellsMenuSpanCoversExactlyItsOwnColumns(): void
    {
        $bar = $this->bar([[new StatusCell('⎇ main', StatusTone::Accent, static fn (): array => [])]]);

        // The row's leading space, then `⎇ main ▾`: its visible width, not its byte length.
        $width = AnsiUtils::visibleWidth('⎇ main ▾');
        $this->assertSame(\range(1, $width), self::menuColumns($bar, 40));
    }

    public function testALaterCellsSpanShiftsRightByWhatPrecedesItSeparatorsIncluded(): void
    {
        // The failure mode: a menu that opens one cell to the left. Every offset
        // is a running sum, so only a second contributor exercises it.
        $bar = $this->bar([
            [new StatusCell('⏸ prog.php:3', StatusTone::Active)],
            [new StatusCell('⎇ main', StatusTone::Accent, static fn (): array => [])],
        ]);

        $from = 1 + AnsiUtils::visibleWidth('⏸ prog.php:3') + 3; // the pad, the cell, ` │ `
        $this->assertSame($from, self::menuColumns($bar, 60)[0] ?? null, 'only the clickable cell answers, from its own first column');
    }

    public function testATextLessCellReservesNoColumnsAtAll(): void
    {
        // Not the same as contributing nothing: an empty cell still went through
        // the loop, and painting it would cost the separator after it —
        // shifting every following cell's menu span off its own text.
        $bar = $this->bar([
            [new StatusCell('')],
            [new StatusCell('⎇ main', StatusTone::Accent, static fn (): array => [])],
        ]);

        $this->assertSame(1, self::menuColumns($bar, 40)[0] ?? null, 'the branch is still the first thing painted');
    }

    public function testOneSourceMayContributeSeveralCellsEachWithItsOwnSpan(): void
    {
        // What the plugin slot does: it speaks for every plugin at once, so its
        // cells must be laid out as peers rather than as one run of text.
        $bar = $this->bar([[
            new StatusCell('▶ build'),
            new StatusCell('⎇ main', StatusTone::Accent, static fn (): array => []),
        ]]);

        $this->assertSame(1 + AnsiUtils::visibleWidth('▶ build') + 3, self::menuColumns($bar, 60)[0] ?? null);
    }
}
