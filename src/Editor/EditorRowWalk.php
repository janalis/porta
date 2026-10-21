<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The editor's content rows from the top of the viewport, walked as the row
 * assembler draws them: the visible lines from `vScroll`, folded ones
 * skipped, each taking its lens rows, its text's rows — several under soft
 * wrap — and its block rows, the top line from `vScrollSegment` on.
 *
 * One walk for everything that has to agree with the screen: a click
 * ({@see EditorMouse}) and a pane drawn level with the editor (the plugin
 * API's `editorScreen()`). Sticky headers are not content rows; they sit above.
 */
final readonly class EditorRowWalk
{
    public function __construct(
        private Document $document,
        private FoldModel $foldModel,
        private DecorationStore $decorations,
    ) {}

    /**
     * The first $count content rows: what each shows, null past the last line.
     *
     * @return list<?EditorRow>
     */
    public function rows(int $count, EditorViewport $vp): array
    {
        $layout = new WrapLayout($this->document);
        $lastLine = $this->document->getLineCount() - 1;
        $rows = [];
        $line = $vp->vScroll;
        $inLine = $vp->vScrollSegment;
        // Bounded by the line count rather than by trusting nextVisibleLine()
        // to advance: a walk that cannot terminate structurally would need a
        // guard branch nothing can reach to test.
        for ($step = 0; \count($rows) < $count && $line <= $lastLine && $step <= $lastLine; ++$step) {
            $lensRows = \count($this->decorations->codeLensesAt($line));
            $segments = null === $vp->wrapWidth ? 1 : $layout->rowsFor($line, $vp->wrapWidth);
            $height = $lensRows + $segments + \count($this->decorations->lineBlocksAt($line));
            for (; $inLine < $height && \count($rows) < $count; ++$inLine) {
                $rows[] = new EditorRow($line, $lensRows, $segments, $inLine);
            }
            $inLine = 0;
            $line = $this->foldModel->nextVisibleLine($line);
        }

        return \array_pad($rows, \max(0, $count), value: null);
    }
}
