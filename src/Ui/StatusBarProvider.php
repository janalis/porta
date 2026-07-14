<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Debug\DebugController;
use Porta\Editor\BufferManager;
use Porta\Git\GitRefresher;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ImageViewerWidget;

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
        private readonly Palette $palette,
        private readonly Translator $translator,
        private readonly string $rootDir,
    ) {}

    /** @return array{left: string, right: string} */
    public function segments(): array
    {
        $active = $this->buffers->active();
        $image = null !== $active && $active->isImage() ? $active->image : null;

        $doc = $this->editor->getDocument();
        $path = $this->editor->getFilePath();

        $left = match (true) {
            null !== $active && (null !== $image || $active->isHelp()) => ProjectPaths::relative($this->rootDir, $active->path),
            null === $path => $this->translator->trans('porta — open a file from the tree (Enter)'),
            default => ProjectPaths::relative($this->rootDir, $path).($doc->isDirty() ? ' ●' : ''),
        };

        if (null !== ($branch = $this->gitRefresher->branch())) {
            $left = $this->palette->of(Role::StatusBranch)."\u{e0a0} {$branch}".$this->palette->fgReset().'  '.$left;
        }

        if (null !== ($debugSegment = $this->debug->statusSegment())) {
            $left = $this->palette->of(Role::StatusDebug).$debugSegment.$this->palette->fgReset().'  '.$left;
        }

        if (null !== $image) {
            // An image tab: dimensions + zoom instead of cursor/LSP/problems.
            $right = \sprintf('%d × %d  %s  ', $image->width, $image->height, $this->imageViewer->zoomLabel()).$this->translator->trans('+/- zoom  0 fit  arrows pan  ^Q quit');

            return ['left' => $left, 'right' => $right];
        }

        if (null !== ($diagnostic = $this->editor->getCursorDiagnostic())) {
            $left .= '  '.$this->palette->of(Role::StatusError).$diagnostic.$this->palette->fgReset();
        }

        $counts = $this->editor->getDiagnosticCounts();
        $problems = '';
        if ($counts['errors'] > 0) {
            $problems .= $this->palette->of(Role::StatusError)."✗ {$counts['errors']}".$this->palette->fgReset().' ';
        }
        if ($counts['warnings'] > 0) {
            $problems .= $this->palette->of(Role::StatusWarning)."▲ {$counts['warnings']}".$this->palette->fgReset().' ';
        }

        $location = \sprintf('%d:%d', $doc->getCursorLine() + 1, $doc->getCursorDisplayCol() + 1);
        $legend = $this->translator->trans('LSP:%status%  ^S save ^T panel ^Space complete ^Q quit', [
            '%status%' => $this->lsp->statusFor($this->editor->getFilePath()),
        ]);
        $right = $problems.$location.'  '.$legend;

        return ['left' => $left, 'right' => $right];
    }
}
