<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Tokens\TokenType;

/**
 * A {@see TerminalTheme} whose backing scheme can be swapped at runtime — the
 * syntax-highlighting counterpart to {@see \Porta\Ui\SwitchablePalette}. Every
 * {@see CodeHighlighter} holds this instance and its tempest Highlighter calls
 * {@see before()}/{@see after()} at parse time, so {@see switchTo()} re-colors
 * all syntax on the next (re-)highlight without rebuilding the highlighters.
 */
final class SwitchableTerminalTheme implements TerminalTheme
{
    public function __construct(
        private TerminalTheme $current,
    ) {}

    /** Swap the backing theme; buffers re-color on their next highlight pass. */
    public function switchTo(TerminalTheme $theme): void
    {
        $this->current = $theme;
    }

    public function before(TokenType $tokenType): string
    {
        return $this->current->before($tokenType);
    }

    public function after(TokenType $tokenType): string
    {
        return $this->current->after($tokenType);
    }

    public function escape(string $content): string
    {
        return $this->current->escape($content);
    }
}
