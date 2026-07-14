<?php

declare(strict_types=1);

namespace Porta\Support;

use Symfony\Component\Tui\Tui;

/** The system clipboard, written through the terminal via OSC 52. */
final class Clipboard
{
    public function __construct(
        private readonly Tui $tui,
    ) {}

    /** Copy $text to the system clipboard (skipping oversized payloads). */
    public function copy(string $text): void
    {
        if (\strlen($text) < 100_000) {
            $this->tui->getTerminal()->write("\x1b]52;c;".base64_encode($text)."\x07");
        }
    }
}
