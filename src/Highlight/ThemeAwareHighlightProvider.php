<?php

declare(strict_types=1);

namespace Porta\Highlight;

/**
 * A {@see SyntaxHighlightProvider} that bakes theme colors at construction and
 * therefore has to be rebuilt when the theme changes.
 *
 * Optional: a provider that reads the live {@see \Tempest\Highlight\TerminalTheme}
 * holder per token needs none of this, because the holder is swapped in place
 * before any repaint. Tempest is the case that does need it — see
 * {@see TempestHighlightProvider::refreshTheme()}.
 */
interface ThemeAwareHighlightProvider extends SyntaxHighlightProvider
{
    public function refreshTheme(): void;
}
