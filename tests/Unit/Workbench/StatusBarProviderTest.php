<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\ServerProgress;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorPaneWidget;
use Porta\Workbench\StatusBarProvider;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusClickSpan;
use Porta\Workbench\StatusSegment;
use Porta\Workbench\StatusTone;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * {@see StatusBarProvider::segments()} beyond the prefix span arithmetic
 * {@see StatusBarPrefixTest} already owns: the left match arms, the pane
 * short-circuit, and the right side's problems/location/legend assembly.
 */
final class StatusBarProviderTest extends TestCase
{
    private TempProject $project;
    private BufferManager $buffers;
    private CodeEditorWidget $editor;
    private EditorPanes $panes;
    private Translator $translator;

    /** @param list<list<StatusCell>> $perSource */
    private function provider(array $perSource = []): StatusBarProvider
    {
        $this->project = new TempProject();
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();
        $this->panes = new EditorPanes();
        $this->translator = new Translator('en');

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
            $this->buffers,
            $this->editor,
            $this->panes,
            $sources,
            new LspSessionPool($this->project->dir, new FixedActiveFile(), $this->translator),
            new ServerProgress(new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20))),
            Styles::dark(),
            $this->translator,
            new ProjectPaths($this->project->dir),
        );
    }

    private function fakePane(string $id, string $statusRight): EditorPaneWidget
    {
        return new class($id, $statusRight) extends EditorPaneWidget {
            public function __construct(
                private readonly string $id,
                private readonly string $statusRight,
            ) {}

            #[\Override]
            public function paneId(): string
            {
                return $this->id;
            }

            #[\Override]
            public function show(string $path, mixed $payload): void {}

            #[\Override]
            public function clear(): void {}

            #[\Override]
            public function statusRight(): string
            {
                return $this->statusRight;
            }

            #[\Override]
            public function render(RenderContext $context): array
            {
                return [];
            }

            #[\Override]
            public function handleInput(string $data): void {}
        };
    }

    public function testLeftFallsBackToTheOpenAFilePromptWhenNoFileIsOpen(): void
    {
        $left = $this->provider()->segments()['left'];

        $this->assertSame($this->translator->trans('porta — open a file from the tree (Enter)'), $left);
    }

    public function testLeftUsesTheActiveViewerBuffersPathWhenActiveIsAViewer(): void
    {
        // A DIFFERENT path on the editor itself proves this came from the
        // viewer-buffer arm, not the default arm reading getFilePath().
        $provider = $this->provider();
        $viewerAbs = $this->project->write('shown.png', 'x');
        $this->buffers->openPane($viewerAbs, 'image', 'payload');
        $this->editor->openFile($this->project->path('editor-side.php'), "<?php\n");

        $left = $provider->segments()['left'];

        $this->assertStringContainsString('shown.png', $left);
        $this->assertStringNotContainsString('editor-side.php', $left);
    }

    public function testLeftUsesTheDefaultArmForANonViewerActiveBufferWithTheDirtyGlyph(): void
    {
        // The active buffer is a normal text buffer (not a viewer), so it
        // must NOT match the first arm even though it is non-null — the
        // default arm's own $path (the editor's, a different buffer) wins.
        $provider = $this->provider();
        $activeAbs = $this->project->write('active.php', "<?php\n");
        $this->buffers->open($activeAbs, "<?php\n");
        $editedAbs = $this->project->write('edited.php', "<?php\n");
        $this->editor->openFile($editedAbs, "<?php\n");
        $this->editor->getDocument()->insert('x'); // dirty

        $left = $provider->segments()['left'];

        $this->assertStringContainsString('edited.php', $left);
        $this->assertStringNotContainsString('active.php', $left);
        $this->assertStringContainsString(Glyphs::DIRTY, $left);
    }

    public function testAnEmptyCellDoesNotAbortTheRestOfItsSource(): void
    {
        // continue vs break only differs within the SAME source's cell loop:
        // an empty cell must skip just itself, not the cells after it.
        $provider = $this->provider([[
            new StatusCell(''),
            new StatusCell('⎇ main', StatusTone::Accent, static fn (): array => []),
        ]]);

        $cells = $provider->segments()['cells'];

        $this->assertCount(1, $cells, 'the empty cell is skipped, and only it');
        $this->assertStringContainsString('main', $cells[0]['text']);
        $this->assertNotNull($cells[0]['menu'], 'the cell after the empty one kept its menu');
    }

    /** A cell's text is styled by its tone, and a cell offering a menu ends in the select's `▾`. */
    public function testACellIsStyledByItsToneAndAMenuCellSaysItOpensOne(): void
    {
        $provider = $this->provider([[new StatusCell('AA', StatusTone::Accent), new StatusCell('⎇ main', StatusTone::Accent, static fn (): array => [])]]);

        $cells = $provider->segments()['cells'];

        $this->assertSame(Styles::dark()->of(Role::StatusAccent)->apply('AA'), $cells[0]['text']);
        $this->assertNull($cells[0]['menu']);
        $this->assertSame('⎇ main '.Glyphs::STATUS_MENU, AnsiUtils::stripAnsiCodes($cells[1]['text']));
    }

    /** Active is the loudest tone, so it is bold as well as coloured: it must read with no colour at all. */
    public function testTheActiveToneIsBold(): void
    {
        $cells = $this->provider([[new StatusCell('▶ debug', StatusTone::Active), new StatusCell('x')]])->segments()['cells'];

        $this->assertStringContainsString("\x1b[1m", $cells[0]['text']);
        $this->assertStringNotContainsString("\x1b[1m", $cells[1]['text'], 'a subtle cell is not');
    }

    public function testEveryCellIsKeptInOrderAndTheLeftIsTheFileAlone(): void
    {
        $provider = $this->provider([[new StatusCell('AA'), new StatusCell('BB')]]);

        $segments = $provider->segments();

        $this->assertSame(['AA', 'BB'], \array_map(static fn (array $cell): string => AnsiUtils::stripAnsiCodes($cell['text']), $segments['cells']));
        $this->assertSame($this->translator->trans('porta — open a file from the tree (Enter)'), $segments['left'], 'the cells are not baked into the left');
    }

    public function testANonEmptyPaneStatusSegmentShortCircuitsTheRightSide(): void
    {
        $provider = $this->provider();
        $abs = $this->project->write('v.bin', 'x');
        $this->buffers->openPane($abs, 'viewer', 'payload');
        $this->panes->register($this->fakePane('viewer', 'PANE-RIGHT'), static fn (string $p): string => '');

        $segments = $provider->segments();

        $this->assertSame('PANE-RIGHT', $segments['right']);
        $this->assertArrayHasKey('left', $segments, 'the early-return array literal must still carry the left segment');
    }

    public function testAnEmptyPaneStatusSegmentFallsThroughToTheDiagnosticsLegend(): void
    {
        $provider = $this->provider();
        $abs = $this->project->write('v.bin', 'x');
        $this->buffers->openPane($abs, 'viewer', 'payload');
        $this->panes->register($this->fakePane('viewer', ''), static fn (string $p): string => '');

        $right = $provider->segments()['right'];

        $this->assertStringContainsString('Ctrl+S', $right, 'an empty pane segment must not short-circuit');
    }

    public function testANonViewerActiveBufferHasNoPaneAtAllAndAlsoFallsThrough(): void
    {
        // paneFor('') resolves to null (no pane registered for the empty id
        // a plain text buffer's null paneId casts to) — a different operand
        // than the empty-string case above, but the same required fallthrough.
        $provider = $this->provider();
        $abs = $this->project->write('t.php', "<?php\n");
        $this->buffers->open($abs, "<?php\n");

        $right = $provider->segments()['right'];

        $this->assertStringContainsString('Ctrl+S', $right);
    }

    public function testProblemsSegmentIsAbsentWithZeroErrorsAndWarnings(): void
    {
        $provider = $this->provider();
        $this->editor->openFile($this->project->path('t.php'), "<?php\n");

        $right = $provider->segments()['right'];

        $this->assertStringNotContainsString(Glyphs::SEVERITY_ERROR, $right);
        $this->assertStringNotContainsString(Glyphs::SEVERITY_WARNING, $right);
    }

    /**
     * The caret readout leads with its severity's glyph and takes its role —
     * a warning is not dressed as an error, and a hint wears no alarm at all.
     */
    public function testTheCaretDiagnosticLeadsWithItsSeverity(): void
    {
        $provider = $this->provider();
        $this->editor->openFile($this->project->path('t.php'), "<?php\n\n\n");
        $this->editor->decorations->setDiagnostics([
            0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 0]],
            1 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'meh', 'col' => 0]],
            2 => [['severity' => DiagnosticSeverity::Hint, 'message' => 'tip', 'col' => 0]],
        ]);
        $styles = Styles::dark();

        $this->assertStringContainsString($styles->of(Role::StatusError)->apply(Glyphs::SEVERITY_ERROR.' boom'), $provider->segments()['left']);
        $this->editor->getDocument()->setCursor(1, 0);
        $this->assertStringContainsString($styles->of(Role::StatusWarning)->apply(Glyphs::SEVERITY_WARNING.' meh'), $provider->segments()['left']);
        $this->editor->getDocument()->setCursor(2, 0);
        $left = $provider->segments()['left'];
        $this->assertStringEndsWith('  '.Glyphs::SEVERITY_HINT.' tip', $left, 'plain: no role at all');
    }

    public function testProblemsSegmentShowsExactlyOneErrorAndOneWarning(): void
    {
        $provider = $this->provider();
        $this->editor->openFile($this->project->path('t.php'), "<?php\n\n");
        $this->editor->decorations
            ->setDiagnostics([
                0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 0]],
                1 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'w', 'col' => 0]],
            ]);

        $right = $provider->segments()['right'];

        $this->assertStringContainsString(Glyphs::SEVERITY_ERROR.' 1', $right);
        $this->assertStringContainsString(Glyphs::SEVERITY_WARNING.' 1', $right);
    }

    /**
     * Each right-hand click span must cover the text it claims. Asserted by
     * slicing the right segment at the span's own columns, so a span that
     * drifts points at the wrong words rather than merely at a wrong number.
     */
    public function testEachRightHandSpanCoversTheCellItActsOn(): void
    {
        $provider = $this->provider();
        $this->editor->openFile($this->project->path('t.php'), "<?php\n\n");
        $this->editor->decorations
            ->setDiagnostics([0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 0]]]);

        $segments = $provider->segments();
        $plain = AnsiUtils::stripAnsiCodes($segments['right']);
        $byAction = [];
        foreach ($segments['rightClicks'] as $span) {
            $byAction[$span->action] = \trim(\mb_substr($plain, $span->from, $span->to - $span->from));
        }

        $this->assertSame(Glyphs::SEVERITY_ERROR.' 1', $byAction['ide_problems']);
        $this->assertSame('1:1', $byAction['goto_line']);
        // The LSP cell and NOTHING after it. `assertStringStartsWith('LSP:')`
        // was true of the whole legend too, which is how a span covering
        // "Ctrl+S save  Ctrl+T panel  Ctrl+Space complete  Ctrl+Q quit" passed
        // this case for as long as it existed: clicking the printed reminder
        // that Ctrl+S saves opened the language-server log.
        $this->assertStringStartsWith('LSP:', $byAction['ide_lsp_log']);
        $this->assertStringNotContainsString('Ctrl+', $byAction['ide_lsp_log'], 'the key hints are not a control');
        $this->assertStringContainsString('Ctrl+S', $plain, 'and they are still ON the bar, just not clickable');
    }

    public function testWithNoProblemsThereIsNoProblemsCellToClick(): void
    {
        $provider = $this->provider();
        $this->editor->openFile($this->project->path('t.php'), "<?php\n\n");

        $segments = $provider->segments();
        $actions = \array_map(static fn (StatusClickSpan $span): string => $span->action, $segments['rightClicks']);

        $this->assertSame(['goto_line', 'ide_lsp_log'], $actions);
    }

    public function testAPaneOwningTheRightSideOffersNothingClickable(): void
    {
        $provider = $this->provider();
        $abs = $this->project->write('v.bin', 'x');
        $this->buffers->openPane($abs, 'viewer', 'payload');
        $this->panes->register($this->fakePane('viewer', 'PANE-RIGHT'), static fn (string $p): string => '');

        $this->assertSame([], $provider->segments()['rightClicks']);
    }

    public function testLocationIsOneBasedFromTheZeroBasedCursor(): void
    {
        $provider = $this->provider();
        $this->editor->openFile($this->project->path('t.php'), "aa\nbb\ncc\ndd\n");
        $this->editor->getDocument()->setCursor(2, 1);

        $right = $provider->segments()['right'];

        $this->assertStringContainsString('3:2', $right, 'line 2/col 1 (0-based) must read "3:2" — any off-by-one or sign flip misses this exact pair');
    }

    public function testTheLspStatusPlaceholderIsSubstitutedNotLeftLiteral(): void
    {
        $provider = $this->provider();
        $this->editor->openFile($this->project->path('t.php'), "<?php\n");

        $right = $provider->segments()['right'];

        $this->assertStringNotContainsString('%status%', $right);
        $this->assertStringContainsString('no server', $right, 'an unconfigured LspSessionPool reports "no server" once substituted');
    }

    public function testRightIsProblemsThenLocationThenATwoColumnGapThenTheLegend(): void
    {
        $provider = $this->provider();
        $this->editor->openFile($this->project->path('t.php'), "aa\nbb\n");
        $this->editor->decorations->setDiagnostics([0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 0]]]);
        $this->editor->getDocument()->setCursor(1, 0);

        $expectedProblems = Styles::dark()->of(Role::StatusError)->apply(Glyphs::SEVERITY_ERROR.' 1').' ';
        $expectedLocation = '2:1';
        $expectedLegend = $this->translator->trans('LSP:%status%  Ctrl+S save  Ctrl+T panel  Ctrl+Space complete  Ctrl+Q quit', [
            '%status%' => $this->translator->trans('no server'),
        ]);

        $right = $provider->segments()['right'];

        $this->assertSame($expectedProblems.$expectedLocation.'  '.$expectedLegend, $right);
    }
}
