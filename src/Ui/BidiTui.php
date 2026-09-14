<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\I18n\PaneReorder;
use Porta\Support\Clocks;
use Porta\Support\PerfCollector;
use Porta\Support\Scheduler;
use Psr\Clock\ClockInterface;
use Revolt\EventLoop;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
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
// Declared, not loader-derived: BidiTui's definition is overridden to
// synthetic AFTER the loader's load-time interface counting, so a derived
// FrameHooks alias would be a load-order accident — and the presenters'
// nullable FrameHooks dep fails silently, so this seam must not hang on one.
#[AsAlias(FrameHooks::class)]
final class BidiTui extends Tui implements FrameHooks
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

    /** A same-iteration tick is already queued — a request burst queues exactly one. */
    private bool $immediateTickQueued = false;

    /** The queued defer's id, so stop() can cancel a pending one (no leaked callback). */
    private ?string $immediateTickId = null;

    /** The terminal's backlog-drained hook is installed (armed once, on first skip). */
    private bool $drainWakeupArmed = false;

    /** Counter sink for skipped/coalesced frame generation; null outside a full Ide. */
    private ?PerfCollector $perf = null;

    /** @var (\Closure(list<string>): void)|null handed every frame's final lines */
    private ?\Closure $frameSink = null;

    /** The terminal reorders bidi text itself, so this one must not. */
    private bool $terminalBidi = false;

    /**
     * The PREVIOUS frame's mirror, row for row: the inputs that produced each
     * arranged row beside the row itself.
     *
     * Mirroring a frame is the most expensive thing a right-to-left session
     * does — measured through the real pipeline at 226x67, 3.7ms a frame
     * against 0.17ms of widget tree and 0.10ms of terminal write. And a repaint
     * changes a row or two: the differential writer downstream is built on
     * exactly that observation, and this is the same observation applied one
     * stage earlier. A row whose line, panes and floating boxes are all
     * unchanged mirrors to the same string it did last frame, so it is reused
     * rather than recomputed.
     *
     * Rebuilt whole each frame rather than written through, which is what keeps
     * it honest: it holds the rows of the LAST frame and nothing else, so a
     * shrinking terminal leaves no stale rows behind to be served later.
     *
     * @var array<int, array{in: string, runs: list<array{start: int, width: int, ltr: bool}>, regions: list<array{start: int, width: int, ltr: bool}>, out: string}>
     */
    private array $arranged = [];

    /** @var array<int, list<array{start: int, width: int, ltr: bool}>> the last frame's floating boxes, for click mapping */
    private array $lastRegions = [];

    /** Minimum seconds between generated frames while pacing is wired (60fps). */
    public const float MIN_FRAME_INTERVAL = 1 / 60;

    /** Wall clock for the frame cap; null (bare test rigs) leaves the cap inert. */
    private ?ClockInterface $clock = null;

    /** Timer seam for the trailing-edge wakeup; null leaves only the ticker retry. */
    private ?Scheduler $scheduler = null;

    /** When the last frame reached the terminal (Clocks::seconds), null before the first. */
    private ?float $lastFrameAt = null;

    /** A forced request (resize, test frames) is pending: the cap must not delay it. */
    private bool $forceRequested = false;

    /** The armed trailing-edge wakeup, at most one per cap window. */
    private ?string $capTimer = null;

    /**
     * The vendor Tui privates the mirrored frame path reads EVERY frame,
     * reflected once on the first RTL frame instead of four times per
     * keystroke.
     *
     * @var array{requested: \ReflectionProperty, root: \ReflectionProperty, renderer: \ReflectionProperty, screenWriter: \ReflectionProperty}|null
     */
    private ?array $tuiPrivates = null;

    /**
     * Tell the frame path that the TERMINAL runs the bidi algorithm itself.
     *
     * Then Porta must not reorder: the row would be reordered twice and come
     * out wrong. It leaves the logical row and only marks the pane edges, so
     * the terminal mirrors each pane within its own span instead of merging
     * them into one run. See {@see PaneReorder::markBoundaries()}.
     */
    public function setTerminalBidi(bool $terminalBidi): void
    {
        $this->terminalBidi = $terminalBidi;
        // The freeze is part of what {@see $arranged} remembers, so every
        // remembered row was mirrored under the OLD answer to this question.
        $this->arranged = [];
    }

    /**
     * @param list<array{start: int, width: int, ltr: bool}> $runs
     * @param list<array{start: int, width: int, ltr: bool}> $regions
     */
    private function arrange(PaneReorder $reorder, string $line, array $runs, array $regions): string
    {
        $row = $reorder->reorderRow($line, $runs, $regions);

        // Porta lays the row out either way. On a terminal that runs the bidi
        // algorithm itself the row then has to be FROZEN, or it is reordered a
        // second time -- measured: each Hebrew run reversed in place while the
        // runs never move, so a menu bar's items came out in the wrong order.
        return $this->terminalBidi ? $reorder->freezeRow($row) : $row;
    }

    /**
     * Install (or clear, with null) a sink handed every frame's FINAL lines —
     * after the per-pane reorder, exactly as they go to the terminal.
     *
     * The instrument for "the screen is wrong and nobody can agree why". A
     * screenshot says what the terminal drew; this says what Porta composed.
     * Put the two side by side and the question of which half is at fault stops
     * being a matter of reading Hebrew glyphs off a photograph — a full round
     * was lost to exactly that.
     *
     * @param (\Closure(list<string>): void)|null $sink
     */
    public function setFrameSink(?\Closure $sink): void
    {
        $this->frameSink = $sink;
    }

    /**
     * Throw away what the screen is believed to hold, so the next frame is a
     * FULL render with a clear and an absolute reposition.
     *
     * The repair for a screen someone else has written to.
     * {@see \Symfony\Component\Tui\Render\ScreenWriter} positions every
     * frame after the first with RELATIVE cursor moves against its own row
     * bookkeeping, and it never emits an absolute reposition on its own — a
     * whole session's output contains no `CSI 2J` and no `CSI H`. So a single
     * stray byte reaching the tty out of band desynchronises the cursor
     * permanently: from then on every differential repaint lands on the wrong
     * row, and nothing ever resyncs.
     *
     * That is not hypothetical. The inner terminal answers the kitty keyboard
     * query by writing `CSI >7u` DIRECTLY to stdout, bypassing this decorator's
     * queue, from the stdin handler — so it can land in the middle of a
     * half-flushed frame. Captured from a real session:
     *
     *     ESC[38;   ESC[>7u   5;240m
     *
     * an SGR cut in half, leaving `5;240m` to be printed as six literal
     * characters. The row is then six columns too wide, the terminal wraps it,
     * and every row below is one off for the rest of the session.
     */
    public function resyncScreen(): void
    {
        // `true` is the whole method: the vendor's forced render resets the
        // screen writer itself, which is what makes the next frame a full one
        // with a clear and an absolute home. Resetting it here as well was
        // redundant -- a mutation said so, by changing no test when removed.
        $this->requestRender(true);
    }

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
    #[\Override]
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
        // A live locale switch replaces the reorder, and rows mirrored by the
        // previous one are not this one's work.
        $this->arranged = [];
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
        if (null === $this->reorder || !\array_key_exists($row, $this->lastLines) || !\array_key_exists($row, $this->lastRuns)) {
            return $visualCol;
        }

        return $this->reorder->logicalColumn($this->lastLines[$row], $visualCol, $this->lastRuns[$row], $this->lastRegions[$row] ?? []);
    }

    /**
     * The vendor's requestRender only raises a flag for the adaptive ticker,
     * whose repeat timer is torn down after every idle frame — so each
     * input-driven frame waited out a fresh ~10ms EventLoop::repeat before
     * painting. Queue ONE deferred tick alongside: the frame paints in the
     * same loop iteration as the input that requested it, and the ticker
     * stays as the retry fallback (e.g. while the terminal back-pressures).
     */
    // @mago-expect lint:no-boolean-flag-parameter -- an #[\Override]: the signature is Tui's, not ours to change
    #[\Override]
    public function requestRender(bool $force = false): void
    {
        if ($force) {
            // A resize (or a test's frame()) demands an immediate full repaint;
            // the cap steps aside until it lands.
            $this->forceRequested = true;
        }
        parent::requestRender($force);
        $this->queueImmediateTick();
    }

    /** Install (or clear, with null) the perf-counter sink (the setPaneReorder idiom). */
    public function setPerfSink(?PerfCollector $sink): void
    {
        $this->perf = $sink;
    }

    /**
     * Wire the frame-rate cap (the setPaneReorder idiom — BidiTui is built by
     * hand before the container exists, so it cannot take these in a
     * constructor). Until this is called the cap is inert: bare test rigs and
     * direct processRender() drives behave exactly as before.
     */
    public function setFramePacing(ClockInterface $clock, Scheduler $scheduler): void
    {
        $this->clock = $clock;
        $this->scheduler = $scheduler;
    }

    /** Queue a same-iteration tick, at most one at a time, only while running. */
    private function queueImmediateTick(): void
    {
        if ($this->immediateTickQueued) {
            $this->perf?->countCoalesced();

            return;
        }
        if (!$this->isRunning()) {
            return;
        }
        $this->immediateTickQueued = true;
        $this->immediateTickId = EventLoop::defer(function (): void {
            $this->immediateTickQueued = false;
            $this->immediateTickId = null;
            // Re-checked at fire time: a stop() between queue and defer must
            // not tick a torn-down terminal.
            if ($this->isRunning()) {
                $this->tick();
            }
        });
    }

    #[\Override]
    public function processRender(): void
    {
        // Frame pacing: while the terminal still holds undelivered bytes, skip
        // generating a new frame. renderRequested stays set (nothing clears
        // it); the drain hook armed below repaints the *current* state the
        // moment the queue empties (the ticker remains the fallback) — the
        // back-pressure the old blocking write provided, without blocking the
        // loop and without dropping bytes.
        $paced = $this->getTerminal();
        if ($paced instanceof NonBlockingTerminal && $paced->pendingBytes() > 0) {
            $this->perf?->countPaceSkip();
            if (!$this->drainWakeupArmed) {
                // Wake the moment the backlog empties: the skipped frame then
                // paints at drain time instead of on the next ticker interval.
                $this->drainWakeupArmed = true;
                $paced->onDrain(function (): void {
                    $this->queueImmediateTick();
                });
            }

            return;
        }

        $this->tuiPrivates ??= [
            'requested' => new \ReflectionProperty(Tui::class, 'renderRequested'),
            'root' => new \ReflectionProperty(Tui::class, 'root'),
            'renderer' => new \ReflectionProperty(Tui::class, 'renderer'),
            'screenWriter' => new \ReflectionProperty(Tui::class, 'screenWriter'),
        ];
        $privates = $this->tuiPrivates;
        $requested = true === $privates['requested']->getValue($this);

        // Frame-rate cap: during an input flood (a trackpad emits 30-120 wheel
        // events per second, each arriving as its own stdin chunk) every event
        // would otherwise generate a full frame and saturate the loop. A frame
        // inside the minimum interval is skipped and ONE trailing wakeup armed
        // for the remainder, so the flood coalesces to the cap rate and the
        // final resting frame always lands. An idle→event frame paints
        // immediately (lastFrameAt is old), and force (resize, test frames)
        // bypasses the cap entirely.
        if ($requested && !$this->forceRequested && null !== $this->clock && null !== $this->lastFrameAt) {
            $elapsed = Clocks::seconds($this->clock) - $this->lastFrameAt;
            if ($elapsed < self::MIN_FRAME_INTERVAL) {
                $this->perf?->countCapSkip();
                if (null === $this->capTimer && null !== $this->scheduler) {
                    $this->capTimer = $this->scheduler->delay(self::MIN_FRAME_INTERVAL - $elapsed, $this->capWakeup(...));
                }

                return; // renderRequested stays set — the wakeup (or ticker) retries
            }
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

        if (!$requested) {
            return;
        }
        // The manual path runs for LTR too — the vendor's processRender does
        // exactly this (clear the flag, render, writeLines; verified, nothing
        // else) and delegating to it hides where a slow frame spends its time.
        // Timing the three stages separately is what lets a real session's
        // perf.log say "widget tree" vs "RTL reorder" vs "terminal write" for a
        // 350ms paint. The middle one earned its own number the hard way: it
        // costs ~8ms at 226x67 and, counted as part of the write, read as a tty
        // that had stopped draining while `pendingBytes` said it had not.
        $privates['requested']->setValue($this, false);

        /** @var ContainerWidget $root */
        $root = $privates['root']->getValue($this);
        /** @var Renderer $renderer */
        $renderer = $privates['renderer']->getValue($this);
        /** @var ScreenWriter $screenWriter */
        $screenWriter = $privates['screenWriter']->getValue($this);

        $terminal = $this->getTerminal();
        $rows = $terminal->getRows();

        // Four stamps under ONE condition, and an unclocked rig stamps 0.0 four
        // times so every stage measures zero on its own — the documented "no
        // clock wired: paints count with a zero duration". The pairwise
        // `null !== $tN && null !== $tM` guards this replaced could not fire:
        // the stamps are taken together, so a mixed state does not exist, and
        // every mutation of those guards escaped because nothing could tell.
        $clock = $this->clock;
        $t0 = null !== $clock ? Clocks::seconds($clock) : 0.0;
        $lines = \array_values($renderer->render($root, $columns, $rows));
        // A frame is never taller than the screen. The renderer does not clip:
        // a flex:0 child gets its intrinsic height whatever the terminal has, so
        // the real workbench composes THREE rows — menu bar, main, status bar —
        // into a one-row terminal, and four into three. Writing the extra one
        // scrolls the alternate screen, and from then on every differential
        // repaint addresses rows with RELATIVE cursor moves that are all one out:
        // a session with no `CSI H` and no `CSI 2J` after its first frame can
        // never recover, which is the permanent-garble report this project has
        // chased from the other end twice.
        $lines = \array_slice($lines, offset: 0, length: $rows);
        $t1 = null !== $clock ? Clocks::seconds($clock) : 0.0;

        $segmenter = $this->segmenter;
        $reorder = $this->reorder;
        if (null !== $segmenter && null !== $reorder) {
            $this->lastLines = $lines; // pre-reorder, row-indexed, for click column mapping
            $this->lastRuns = $segmenter->segment($root, $columns, $rows);
            // The floating surfaces are a SECOND layer, stamped over the panes
            // after they have been reordered over their full spans. Merged into
            // the pane runs they truncated whatever they covered, and the
            // shortened span re-aligned the pane's whole row.
            $regions = $segmenter->segmentRegions($columns, $rows);
            $this->lastRegions = $regions; // a click inside a box maps through it, not the pane under it
            $out = [];
            $arranged = [];
            foreach ($lines as $row => $line) {
                // Both maps are filled for the same 0..rows-1, and the frame is
                // clipped to $rows above, so every row has both. The guard that
                // used to stand here — write the row unmirrored — was reachable
                // only through an over-tall frame, which is the thing the clip
                // now makes impossible.
                \assert(\array_key_exists($row, $this->lastRuns), description: 'a clipped frame has no row the segmenter did not map');
                $runs = $this->lastRuns[$row];
                $region = $regions[$row];
                $hit = $this->arranged[$row] ?? null;
                // All three, because all three are inputs: the same text under
                // a resized pane, or under a dropdown that has just opened over
                // it, mirrors to something else entirely.
                $line =
                    null !== $hit && $hit['in'] === $line && $hit['runs'] === $runs && $hit['regions'] === $region
                        ? $hit['out']
                        : $this->arrange($reorder, $line, $runs, $region);
                $arranged[$row] = ['in' => $lines[$row], 'runs' => $runs, 'regions' => $region, 'out' => $line];
                $out[] = $line;
            }
            $this->arranged = $arranged;
            $lines = $out;
        }
        // Before the frame sink, which belongs to neither stage and costs
        // 0.06ms at 226x67 (measured) — small enough to leave with the write
        // rather than mint a third boundary for it.
        $t1b = null !== $clock ? Clocks::seconds($clock) : 0.0;
        if (null !== $this->frameSink) {
            ($this->frameSink)($lines);
        }
        $screenWriter->writeLines($lines);
        $t2 = null !== $clock ? Clocks::seconds($clock) : 0.0;

        $this->framePainted($t1 - $t0, $t2 - $t1b, $t1b - $t1);
    }

    /** The trailing-edge wakeup: the cap window elapsed, repaint the pending state. */
    private function capWakeup(): void
    {
        $this->capTimer = null;
        $this->queueImmediateTick();
    }

    /** A frame reached the terminal: stamp the cap window and feed the sink. */
    private function framePainted(float $renderSeconds, float $writeSeconds, float $bidiSeconds): void
    {
        $this->forceRequested = false;
        if (null !== $this->clock) {
            $this->lastFrameAt = Clocks::seconds($this->clock);
        }
        $this->perf?->paintMark($renderSeconds, $writeSeconds, $bidiSeconds);
    }

    #[\Override]
    public function stop(): void
    {
        // Pending wakeups must not survive into the next test's loop.
        if (null !== $this->capTimer) {
            $this->scheduler?->cancel($this->capTimer);
            $this->capTimer = null;
        }
        if (null !== $this->immediateTickId) {
            EventLoop::cancel($this->immediateTickId);
            $this->immediateTickId = null;
            $this->immediateTickQueued = false;
        }
        parent::stop();
    }
}
