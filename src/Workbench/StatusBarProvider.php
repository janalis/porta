<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Support\ProjectPaths;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The status bar's two segments, pulled at render time: the active file (with
 * branch/debug prefixes and the caret diagnostic) on the left; image
 * dimensions+zoom or problems+caret+LSP legend on the right.
 */
final class StatusBarProvider
{
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly CodeEditorWidget $editor,
        private readonly EditorPanes $panes,
        /** @var iterable<StatusSegment> ambient-state contributors, core's first (see StatusSegment) */
        #[AutowireIterator('porta.status_segment')]
        private readonly iterable $segmentSources,
        private readonly LspSessionPool $lsp,
        private readonly Styles $styles,
        private readonly Translator $translator,
        private readonly string $rootDir,
    ) {}

    /**
     * The two status segments plus, for every clickable cell, its column range
     * within `left` and the menu it offers — so the router can hit-test a
     * right-click without knowing which cell is which. Ranges shift right as
     * earlier segments appear and disappear.
     *
     * @return array{left: string, right: string, menus: list<StatusMenuSpan>}
     */
    public function segments(): array
    {
        $active = $this->buffers->active();

        $doc = $this->editor->getDocument();
        $path = $this->editor->getFilePath();

        $left = match (true) {
            null !== $active && $active->isViewer() => ProjectPaths::relative($this->rootDir, $active->path),
            null === $path => $this->translator->trans('porta — open a file from the tree (Enter)'),
            default => ProjectPaths::relative($this->rootDir, $path).($doc->isDirty() ? ' '.Glyphs::DIRTY : ''),
        };

        // Build the left prefix in visual order — [contributed segments] [branch]
        // — so the branch's column offset already accounts for whatever precedes
        // it (each part is independently exercised; their sum needs no third
        // path). The debugger is one contributor among them now, which is what
        // takes Porta\Debug out of this file.
        $menus = [];
        $prefix = '';
        $prefixWidth = 0;
        foreach ($this->segmentSources as $source) {
            foreach ($source->statusCells() as $cell) {
                if ('' === $cell->text) {
                    continue;
                }
                $width = AnsiUtils::visibleWidth($cell->text);
                if (null !== $cell->menu) {
                    $menus[] = new StatusMenuSpan($prefixWidth, $prefixWidth + $width, $cell->menu);
                }
                $prefix .= $this->styles->of(self::role($cell->tone))->apply($cell->text).'  ';
                $prefixWidth += $width + 2;
            }
        }
        $left = $prefix.$left;

        // A pane answers for its own right-hand segment — extent, zoom and the
        // keys it binds. The two branches this replaced held one hint string per
        // viewer, so the status bar had to know which viewer was showing; a pane
        // that contributes nothing (the help pane) returns '' and falls through
        // to the editor's diagnostics/LSP legend below.
        $paneSegment = $this->panes->paneFor((string) $active?->paneId)?->statusRight();
        if (null !== $paneSegment && '' !== $paneSegment) {
            return ['left' => $left, 'right' => $paneSegment, 'menus' => $menus];
        }

        if (null !== ($diagnostic = $this->editor->getCursorDiagnostic())) {
            $left .= '  '.$this->styles->of(Role::StatusError)->apply($diagnostic);
        }

        $counts = $this->editor->getDiagnosticCounts();
        $problems = '';
        if ($counts['errors'] > 0) {
            $problems .= $this->styles->of(Role::StatusError)->apply(Glyphs::SEVERITY_ERROR." {$counts['errors']}").' ';
        }
        if ($counts['warnings'] > 0) {
            $problems .= $this->styles->of(Role::StatusWarning)->apply(Glyphs::SEVERITY_WARNING." {$counts['warnings']}").' ';
        }

        $location = \sprintf('%d:%d', $doc->getCursorLine() + 1, $doc->getCursorDisplayCol() + 1);
        $legend = $this->translator->trans('LSP:%status%  Ctrl+S save  Ctrl+T panel  Ctrl+Space complete  Ctrl+Q quit', [
            '%status%' => $this->lsp->statusFor($this->editor->getFilePath()),
        ]);
        $right = $problems.$location.'  '.$legend;

        return ['left' => $left, 'right' => $right, 'menus' => $menus];
    }

    /** A contributor asks for prominence; core decides the colour, so the theme lock holds. */
    private static function role(StatusTone $tone): Role
    {
        return match ($tone) {
            StatusTone::Subtle => Role::Subtle,
            StatusTone::Accent => Role::StatusAccent,
            StatusTone::Active => Role::StatusActive,
        };
    }
}
