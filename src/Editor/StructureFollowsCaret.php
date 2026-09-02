<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsEager;
use Porta\Ui\FrameHooks;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\ResultsPanelMode;

/**
 * PhpStorm's "autoscroll from source": while a Structure tab is on display, its
 * selection follows the caret to whichever symbol encloses it.
 *
 * No language-server round trip — the tab's rows already carry each symbol's
 * line, and they arrive in document order, so the enclosing symbol is the LAST
 * row at or above the caret. That is also why this can run per frame where the
 * semantic highlight needs a settle timer: the work is a walk over rows already
 * in memory, and only when the caret actually moved and the tab is visible.
 */
#[AsEager]
final class StructureFollowsCaret
{
    private ?string $lastPath = null;
    private int $lastLine = -1;

    public function __construct(
        private readonly CodeEditorWidget $editor,
        private readonly BottomDock $dock,
        ?FrameHooks $frames = null,
    ) {
        $frames?->onBeforeFrame($this->caretMoved(...));
    }

    /** Cheap and idempotent, per the hook's contract. */
    public function caretMoved(): void
    {
        $path = $this->editor->getFilePath();
        $line = $this->editor->getDocument()->getCursorLine();
        if (null === $path || $path === $this->lastPath && $line === $this->lastLine) {
            return;
        }
        $this->lastPath = $path;
        $this->lastLine = $line;

        $enclosing = $this->enclosingRow($this->dock->displayedRows(ResultsPanelMode::Structure), $line);
        if (null !== $enclosing) {
            $this->dock->selectInList(ResultsPanelMode::Structure, $enclosing['path'], $enclosing['line']);
        }
    }

    /**
     * The last row at or above the caret. Rows are in document order, so that
     * is the innermost symbol containing it — a method rather than its class.
     * A caret ABOVE the first symbol (the file's header) encloses nothing, and
     * selecting the first symbol there would be a lie.
     *
     * @param list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> $rows
     *
     * @return array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}|null
     */
    private function enclosingRow(array $rows, int $line): ?array
    {
        $found = null;
        foreach ($rows as $row) {
            if ($row['line'] > $line) {
                break;
            }
            $found = $row;
        }

        return $found;
    }
}
