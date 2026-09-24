<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * The live {@see Density}, the {@see CursorStyle} pattern: one shared holder a
 * frame hook updates from the terminal's rows and every widget that sizes its
 * chrome reads, so a resize across the threshold flips them all in the same
 * frame. Roomy until observed — a widget built bare in a test is boxed.
 */
final class ScreenDensity
{
    private Density $density = Density::Roomy;

    public function current(): Density
    {
        return $this->density;
    }

    /** Take the density a terminal of $terminalRows affords; whether it changed. */
    public function observe(int $terminalRows): bool
    {
        $density = Density::of($terminalRows);
        if ($density === $this->density) {
            return false;
        }
        $this->density = $density;

        return true;
    }
}
