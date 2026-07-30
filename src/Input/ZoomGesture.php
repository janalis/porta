<?php

declare(strict_types=1);

namespace Porta\Input;

/**
 * Tells a Ctrl+wheel BURST apart from a single Ctrl+wheel CLICK, so the viewers
 * can answer each the way its device deserves.
 *
 * A trackpad pinch never reaches a terminal program — macOS delivers it as an
 * NSEvent magnify gesture, the terminal keeps it (iTerm2 spends it on font
 * size), and no mouse protocol encodes a magnification. What a trackpad DOES
 * send is Ctrl+two-finger-scroll: a burst of 8-40 ordinary wheel notches about
 * 10-30ms apart. Answering each of those with a full rung of a doubling ladder
 * is what made the existing zoom useless as a pinch — one flick crossed the
 * whole ladder.
 *
 * So a notch that continues a burst asks for a small FACTOR instead
 * ({@see \Porta\Image\ZoomPanModel::magnifyAt()}), and the gesture glides. A
 * notch that stands alone — a real mouse wheel, or a deliberate single
 * scroll — still gets null, and the caller steps the ladder exactly as before.
 *
 * The window is 0.1s: an order of magnitude above trackpad notch spacing and
 * comfortably below any human's repeated wheel click. Timing state lives here
 * rather than in the router for the same reason {@see ClickCounter}'s does, and
 * like it this is newed by its owner, not injected — a clock and a float are not
 * a service.
 */
final class ZoomGesture
{
    /** Notches closer together than this are one gesture, not two decisions. */
    private const float BURST_SECONDS = 0.1;

    /** Per-notch magnification mid-gesture — ~8%, so a flick of a dozen notches roughly doubles. */
    private const float FACTOR = 1.08;

    private float $lastTime = 0.0;

    /**
     * The factor for a notch continuing a gesture, or null when this notch stands
     * alone and the caller should step the zoom ladder instead.
     *
     * @param int $dir 1 to zoom in, -1 to zoom out
     */
    public function glideFactor(int $dir): ?float
    {
        $now = microtime(as_float: true);
        $burst = ($now - $this->lastTime) < self::BURST_SECONDS;
        $this->lastTime = $now;

        if (!$burst) {
            return null;
        }

        return $dir > 0 ? self::FACTOR : 1 / self::FACTOR;
    }
}
