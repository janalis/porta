<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Editor\BufferManager;
use Porta\Editor\DiagnosticSeverity;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\ServerProgress;
use Porta\Support\ProjectPaths;
use Porta\Ui\Glyphs;
use Porta\Ui\KeyHints;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorPaneWidget;
use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Style;

/**
 * The status bar's two segments, pulled at render time: the active file (with
 * branch/debug prefixes and the caret diagnostic) on the left; image
 * dimensions+zoom, or server activity + problems + caret + LSP legend, on the
 * right.
 */
final readonly class StatusBarProvider
{
    public function __construct(
        private BufferManager $buffers,
        private CodeEditorWidget $editor,
        private EditorArea $editorArea,
        /** @var iterable<StatusSegment> ambient-state contributors, core's first (see StatusSegment) */
        #[AutowireIterator('porta.status_segment')]
        private iterable $segmentSources,
        private LspSessionPool $lsp,
        private ServerProgress $progress,
        private Styles $styles,
        private Translator $translator,
        private ProjectPaths $paths,
        private Keybindings $ideKeys,
    ) {}

    /**
     * The contributed cells, each with the menu it offers; the file part
     * (`left`); and the right segment with its left-clickable spans. The
     * widget lays the cells out — it alone knows which of them fit — so the
     * router still hit-tests a click without knowing which cell is which.
     *
     * @return array{cells: list<array{text: string, menu: (\Closure(): list<MenuItem>)|null, tooltip: ?string}>, left: string, leftTooltip: ?string, leftPath: ?string, leftPathWidth: int, leftClicks?: list<StatusClickSpan>, right: string, rightClicks: list<StatusClickSpan>}
     */
    public function segments(): array
    {
        $active = $this->buffers->active();

        $doc = $this->editor->getDocument();
        $path = $this->editor->getFilePath();

        $left = match (true) {
            null !== $active && $active->isViewer() => $this->paths->relative($active->path),
            null === $path => $this->translator->trans('porta — open a file from the tree (Enter)'),
            default => $this->paths->relative($path).($doc->isDirty() ? ' '.Glyphs::DIRTY : ''),
        };
        // The file the left part names, and how wide its path is on its own:
        // the ● and a caret diagnostic follow it, and only the path is the
        // tree's file (a double-click reveals it, a right-click offers its menu).
        $leftPath = null !== $active && $active->isViewer() ? $active->path : $path;
        $leftPathWidth = null === $leftPath ? 0 : AnsiUtils::visibleWidth($this->paths->relative($leftPath));
        // The file part is cut in the middle when the row is short, and its
        // ● is a glyph: the tooltip spells both out.
        $leftTooltip = match (true) {
            null !== $active && $active->isViewer() => $this->paths->relative($active->path),
            null === $path => null,
            default => $this->paths->relative($path).($doc->isDirty() ? "\n".$this->translator->trans('Unsaved changes') : ''),
        };

        // The contributed cells in visual order — the debugger, the branch —
        // styled by their tone, and a cell with a menu says so with `▾`: it
        // is a control, which the row had no way to show. The debugger is one
        // contributor among them, which is what keeps Porta\Debug out of here.
        $cells = [];
        foreach ($this->segmentSources as $source) {
            foreach ($source->statusCells() as $cell) {
                if ('' === $cell->text) {
                    continue;
                }
                $text = null === $cell->menu ? $cell->text : $cell->text.' '.Glyphs::STATUS_MENU;
                $cells[] = ['text' => $this->style($cell->tone)->apply($text), 'menu' => $cell->menu, 'tooltip' => $cell->tooltip];
            }
        }

        // A pane answers for its own right-hand segment — extent, zoom and the
        // keys it binds. The two branches this replaced held one hint string per
        // viewer, so the status bar had to know which viewer was showing; a pane
        // that contributes nothing (the help pane) returns '' and falls through
        // to the editor's diagnostics/LSP legend below.
        // A preview speaks for itself the same way while it has the keys —
        // shown alone, or clicked beside the editor; while the editor has
        // them, the caret's legend is the one that means something.
        $pane = $this->editorArea->widget();
        $paneSegment = $pane instanceof EditorPaneWidget ? $pane->statusRight() : '';
        if ('' !== $paneSegment && $pane instanceof EditorPaneWidget) {
            // A pane owns its whole right side, what a click there does too:
            // core has nothing of its own to offer in it.
            return [
                'cells' => $cells,
                'left' => $left,
                'leftTooltip' => $leftTooltip,
                'leftPath' => $leftPath,
                'leftPathWidth' => $leftPathWidth,
                'right' => $paneSegment,
                'rightClicks' => $pane->statusClicks(),
            ];
        }

        // Led by its severity's glyph, so an error and a hint differ without colour.
        $leftClicks = [];
        if (null !== ($diagnostic = $this->editor->getCursorDiagnostic())) {
            $text = $diagnostic['severity']->glyph().' '.$diagnostic['message'];
            $leftTooltip = null === $leftTooltip ? $text : $leftTooltip."\n".$text; // whole, where the row may cut it
            // A click on it opens it in the Problems panel.
            $from = AnsiUtils::visibleWidth($left) + 2;
            $leftClicks[] = new StatusClickSpan(
                $from,
                $from + AnsiUtils::visibleWidth($text),
                'ide_reveal_caret_problem',
                $text."\n".$this->translator->trans('Click to show it in the Problems panel'),
            );
            $left .= '  '.match ($diagnostic['severity']) {
                DiagnosticSeverity::Error => $this->styles->of(Role::StatusError)->apply($text),
                DiagnosticSeverity::Warning => $this->styles->of(Role::StatusWarning)->apply($text),
                DiagnosticSeverity::Information, DiagnosticSeverity::Hint => $text,
            };
        }

        $counts = $this->editor->getDiagnosticCounts();
        $problems = '';
        if ($counts['errors'] > 0) {
            $problems .= $this->styles->of(Role::StatusError)->apply(Glyphs::SEVERITY_ERROR." {$counts['errors']}").' ';
        }
        if ($counts['warnings'] > 0) {
            $problems .= $this->styles->of(Role::StatusWarning)->apply(Glyphs::SEVERITY_WARNING." {$counts['warnings']}").' ';
        }

        $location = \sprintf('%d:%d', $doc->cursorLine + 1, $doc->getCursorDisplayCol() + 1);
        // Two strings, not one, because only the FIRST of them is clickable.
        // As a single legend the `ide_lsp_log` span below covered the whole of
        // it, so clicking the words "Ctrl+S save" opened the LSP log — the key
        // hints are a printed reminder, and nothing about them says "log".
        // Every locale already put `LSP:%status%` first and separated it by two
        // spaces, so the split costs the catalogs nothing but a cut.
        $lspCell = $this->translator->trans('LSP:%status%', [
            '%status%' => $this->lsp->statusFor($this->editor->getFilePath()),
        ]);
        // From the live keymap: a rebound save used to keep saying Ctrl+S.
        $hints = KeyHints::legend(
            ['save' => '%key% save', 'ide_toggle_focus' => '%key% panel', 'trigger_completion' => '%key% complete', 'ide_quit' => '%key% quit'],
            fn (string $message, string $key): string => $this->translator->trans($message, ['%key%' => $key]),
            '  ',
            $this->editor->getKeybindings(),
            $this->ideKeys,
        );
        $legend = $lspCell.'  '.$hints;
        // What the server is busy doing, ahead of the counts: it is the most
        // perishable thing on the bar, and until now the IDE never said it at
        // all. Carries no glyph — every glyph here must be one display column
        // wide, and the title says plainly enough what is happening.
        $activity = $this->progress->label();
        $busy = null === $activity ? '' : $this->styles->of(Role::StatusAccent)->apply($activity).'  ';
        $right = $busy.$problems.$location.'  '.$legend;

        // Where each right-hand cell sits WITHIN `right` — the widget shifts
        // them onto the row, because only it knows where the right segment
        // landed after the gap is computed. These used to be plain strings, so
        // the most-looked-at numbers on the screen did nothing when clicked.
        $clicks = [];
        $at = AnsiUtils::visibleWidth($busy);
        $problemsWidth = AnsiUtils::visibleWidth($problems);
        if ($problemsWidth > 0) {
            $clicks[] = new StatusClickSpan($at, $at + $problemsWidth, 'ide_problems', $this->translator->trans('Errors and warnings in this file — click to list them'));
        }
        $at += $problemsWidth;
        $locationWidth = AnsiUtils::visibleWidth($location);
        $clicks[] = new StatusClickSpan($at, $at + $locationWidth, 'goto_line', $this->translator->trans('Line %line%, column %column% — click to go to a line', [
            '%line%' => $doc->cursorLine + 1,
            '%column%' => $doc->getCursorDisplayCol() + 1,
        ]));
        // +2 for the gap between the caret position and the legend.
        $at += $locationWidth + 2;
        // The LSP cell ALONE, not the legend it leads: the hints after it are
        // not a control, and a click landing on one used to open the server log.
        $clicks[] = new StatusClickSpan($at, $at + AnsiUtils::visibleWidth($lspCell), 'ide_lsp_log', $this->translator->trans('The language server for this file — click for its log'));

        return [
            'cells' => $cells,
            'left' => $left,
            'leftTooltip' => $leftTooltip,
            'leftPath' => $leftPath,
            'leftPathWidth' => $leftPathWidth,
            'leftClicks' => $leftClicks,
            'right' => $right,
            'rightClicks' => $clicks,
        ];
    }

    /**
     * A contributor asks for prominence; core decides the colour, so the theme
     * lock holds. Active is bold as well: a running debugger reads as the
     * loudest cell with no colour at all.
     */
    private function style(StatusTone $tone): Style
    {
        $style = $this->styles->of(self::role($tone));

        return StatusTone::Active === $tone ? $style->withBold() : $style;
    }

    private static function role(StatusTone $tone): Role
    {
        return match ($tone) {
            StatusTone::Subtle => Role::Subtle,
            StatusTone::Accent => Role::StatusAccent,
            StatusTone::Active => Role::StatusActive,
        };
    }
}
