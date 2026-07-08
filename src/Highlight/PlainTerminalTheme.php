<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Themes\EscapesTerminalTheme;
use Tempest\Highlight\Tokens\TokenType;

/**
 * A colorless terminal theme for `--no-ansi`: every token renders as plain text,
 * so syntax highlighting emits no SGR color. Used in monochrome mode alongside
 * the {@see \Porta\Ui\MonochromePalette}, which does the same for the chrome.
 */
final class PlainTerminalTheme implements TerminalTheme
{
    use EscapesTerminalTheme;

    public function before(TokenType $tokenType): string
    {
        return '';
    }

    public function after(TokenType $tokenType): string
    {
        return '';
    }
}
