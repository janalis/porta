<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Themes\EscapesTerminalTheme;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * 256-color dark theme for terminal syntax highlighting.
 */
final class DarkTerminalTheme implements TerminalTheme
{
    use EscapesTerminalTheme;

    public function before(TokenType $tokenType): string
    {
        return match ($tokenType) {
            TokenTypeEnum::KEYWORD, TokenTypeEnum::LITERAL => "\x1b[38;5;176m", // purple
            TokenTypeEnum::TYPE => "\x1b[38;5;222m", // pale yellow
            TokenTypeEnum::VALUE => "\x1b[38;5;114m", // green (strings)
            TokenTypeEnum::NUMBER => "\x1b[38;5;215m", // orange
            TokenTypeEnum::PROPERTY => "\x1b[38;5;117m", // light blue
            TokenTypeEnum::VARIABLE => "\x1b[38;5;210m", // salmon
            TokenTypeEnum::OPERATOR => "\x1b[38;5;249m",
            TokenTypeEnum::GENERIC => "\x1b[38;5;80m", // cyan
            TokenTypeEnum::ATTRIBUTE => "\x1b[38;5;144m",
            TokenTypeEnum::COMMENT => "\x1b[38;5;243m", // gray
            default => '',
        };
    }

    public function after(TokenType $tokenType): string
    {
        return "\x1b[39m";
    }
}
