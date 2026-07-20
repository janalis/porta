<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Debug\DebugController;
use Porta\Editor\BufferManager;
use Porta\Git\GitRefresher;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Support\ProjectPaths;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ImageViewerWidget;
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
        private readonly ImageViewerWidget $imageViewer,
        private readonly GitRefresher $gitRefresher,
        private readonly DebugController $debug,
        private readonly LspSessionPool $lsp,
        private readonly Styles $styles,
        private readonly Translator $translator,
        private readonly string $rootDir,
    ) {}

    /**
     * The two status segments plus the branch cell's column range within `left`
     * (null when there is no branch), so the router can hit-test a right-click
     * on the branch. The range shifts right when a debug segment precedes it.
     *
     * @return array{left: string, right: string, branchStart: int|null, branchEnd: int|null}
     */
    public function segments(): array
    {
        $active = $this->buffers->active();
        $image = null !== $active && $active->isImage() ? $active->image : null;

        $doc = $this->editor->getDocument();
        $path = $this->editor->getFilePath();

        $left = match (true) {
            null !== $active && (null !== $image || $active->isHelp()) => ProjectPaths::relative($this->rootDir, $active->path),
            null === $path => $this->translator->trans('porta — open a file from the tree (Enter)'),
            default => ProjectPaths::relative($this->rootDir, $path).($doc->isDirty() ? ' '.Glyphs::DIRTY : ''),
        };

        // Build the left prefix in visual order — [debug] [branch] — so the
        // branch's column offset already accounts for a preceding debug segment
        // (each part is independently exercised; their sum needs no third path).
        $branchStart = null;
        $branchEnd = null;
        $prefix = '';
        $prefixWidth = 0;
        if (null !== ($debugSegment = $this->debug->statusSegment())) {
            $prefix .= $this->styles->of(Role::StatusDebug)->apply($debugSegment).'  ';
            $prefixWidth += AnsiUtils::visibleWidth($debugSegment) + 2;
        }
        if (null !== ($branch = $this->gitRefresher->branch())) {
            $branchText = "\u{e0a0} {$branch}";
            $branchStart = $prefixWidth;
            $branchEnd = $prefixWidth + AnsiUtils::visibleWidth($branchText);
            $prefix .= $this->styles->of(Role::StatusBranch)->apply($branchText).'  ';
        }
        $left = $prefix.$left;

        if (null !== $image) {
            // An image tab: dimensions + zoom instead of cursor/LSP/problems.
            $right = \sprintf('%d × %d  %s  %s', $image->width, $image->height, $this->imageViewer->zoomLabel(), $this->translator->trans('+/- zoom  0 fit  Arrows pan  Ctrl+Q quit'));

            return ['left' => $left, 'right' => $right, 'branchStart' => $branchStart, 'branchEnd' => $branchEnd];
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

        return ['left' => $left, 'right' => $right, 'branchStart' => $branchStart, 'branchEnd' => $branchEnd];
    }
}
