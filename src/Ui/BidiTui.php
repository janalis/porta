<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\I18n\PaneReorder;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\ScreenWriter;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * A {@see Tui} that can post-process each composed frame line before it reaches
 * the screen — the single choke point for the RTL bidi reorder, which the
 * framework's final {@see Renderer}/{@see ScreenWriter} (both final) don't
 * otherwise expose. When no reorder is set it defers entirely to the parent,
 * so left-to-right locales run the stock render path untouched.
 *
 * The parent renders and writes privately inside {@see Tui::processRender()};
 * with a reorder active we reproduce that step, reordering each row *per pane*
 * (a {@see PaneSegmenter} splits the row into pane column runs, a
 * {@see PaneReorder} flips each within its own span), reading the parent's
 * private collaborators reflectively (the class is pinned).
 */
final class BidiTui extends Tui
{
    private ?PaneSegmenter $segmenter = null;
    private ?PaneReorder $reorder = null;

    /** @var list<string> the last frame's pre-reorder lines, for visual→logical column mapping */
    private array $lastLines = [];

    /** @var array<int, list<array{start: int, width: int, ltr: bool}>> the last frame's pane runs, per row */
    private array $lastRuns = [];

    /** The width the last frame rendered at, to fire the resize hook exactly on change. */
    private ?int $lastColumns = null;

    /** @var (callable(int): void)|null fired with the new width before the frame renders */
    private $onColumnsChanged = null;

    /** @var list<callable(): void> fired before every frame renders (both render paths) */
    private array $onBeforeFrame = [];

    /**
     * Install a hook fired whenever the terminal width changes (including the
     * first frame), before the frame renders — so a re-arrangement it makes
     * paints in the same frame. The render loop already re-reads the width
     * every frame, which coalesces SIGWINCH bursts for free.
     *
     * @param callable(int): void $hook
     */
    public function onColumnsChanged(callable $hook): void
    {
        $this->onColumnsChanged = $hook;
    }

    /**
     * Install a hook fired before every frame renders, on both render paths.
     * The floating modals (Welcome, Find in Files) use it to lay themselves
     * out from the previous frame's pane rects — geometry that does not exist
     * at open() time (first-run opens before any frame) and that a terminal
     * resize invalidates. Hooks accumulate; each must be cheap and
     * idempotent: they run on every render tick.
     *
     * @param callable(): void $hook
     */
    public function onBeforeFrame(callable $hook): void
    {
        $this->onBeforeFrame[] = $hook;
    }

    /**
     * Install (or clear, with nulls) the per-pane reorder applied to every frame.
     * Both are set together: null on the left-to-right stock path.
     */
    public function setPaneReorder(?PaneSegmenter $segmenter, ?PaneReorder $reorder): void
    {
        $this->segmenter = $segmenter;
        $this->reorder = $reorder;
        if (null === $reorder) {
            $this->lastLines = [];
            $this->lastRuns = [];
        }
    }

    /** Whether the per-pane RTL reorder is active — frames render mirrored. */
    public function isMirrored(): bool
    {
        return null !== $this->reorder;
    }

    /**
     * Map a screen (visual) column on $row back to the logical column that the
     * widgets rendered, inverting the RTL reorder within the pane the column
     * falls in. Identity when no reorder is active or the row was not in the
     * last frame.
     */
    public function logicalColumn(int $row, int $visualCol): int
    {
        if (null === $this->reorder || !array_key_exists($row, $this->lastLines) || !array_key_exists($row, $this->lastRuns)) {
            return $visualCol;
        }

        return $this->reorder->logicalColumn($this->lastLines[$row], $visualCol, $this->lastRuns[$row]);
    }

    public function processRender(): void
    {
        // Frame pacing: while the terminal still holds undelivered bytes, skip
        // generating a new frame. renderRequested stays set (nothing clears
        // it), so the active ticker retries and paints the *current* state the
        // moment the queue drains — the back-pressure the old blocking write
        // provided, without blocking the loop and without dropping bytes.
        $paced = $this->getTerminal();
        if ($paced instanceof NonBlockingTerminal && $paced->pendingBytes() > 0) {
            return;
        }

        $columns = $this->getTerminal()->getColumns();
        if ($columns !== $this->lastColumns) {
            $this->lastColumns = $columns;
            if (null !== $this->onColumnsChanged) {
                ($this->onColumnsChanged)($columns);
            }
        }
        foreach ($this->onBeforeFrame as $hook) {
            $hook();
        }

        $segmenter = $this->segmenter;
        $reorder = $this->reorder;
        if (null === $segmenter || null === $reorder) {
            parent::processRender();

            return;
        }

        $requested = new \ReflectionProperty(Tui::class, 'renderRequested');
        if (true !== $requested->getValue($this)) {
            return;
        }
        $requested->setValue($this, false);

        /** @var ContainerWidget $root */
        $root = new \ReflectionProperty(Tui::class, 'root')->getValue($this);
        /** @var Renderer $renderer */
        $renderer = new \ReflectionProperty(Tui::class, 'renderer')->getValue($this);
        /** @var ScreenWriter $screenWriter */
        $screenWriter = new \ReflectionProperty(Tui::class, 'screenWriter')->getValue($this);

        $terminal = $this->getTerminal();
        $columns = $terminal->getColumns();
        $rows = $terminal->getRows();
        $lines = array_values($renderer->render($root, $columns, $rows));
        $this->lastLines = $lines; // pre-reorder, row-indexed, for click column mapping
        $this->lastRuns = $segmenter->segment($root, $columns, $rows);

        $out = [];
        foreach ($lines as $row => $line) {
            $out[] = array_key_exists($row, $this->lastRuns) ? $reorder->reorderRow($line, $this->lastRuns[$row]) : $line;
        }
        $screenWriter->writeLines($out);
    }
}
