<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Themes\EscapesTerminalTheme;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * 256-color high-contrast theme for terminal syntax highlighting: the syntax
 * mirror of {@see \Porta\Ui\ColorPalette::highContrast()}, selected when the
 * `theme` setting is `high-contrast`. Every token color sits at WCAG-AAA
 * contrast against the terminal's black background.
 */
final class HighContrastTerminalTheme implements TerminalTheme
{
    use EscapesTerminalTheme;

    public function before(TokenType $tokenType): string
    {
        return match ($tokenType) {
            TokenTypeEnum::KEYWORD, TokenTypeEnum::LITERAL => "\x1b[38;5;213m", // bright pink
            TokenTypeEnum::TYPE => "\x1b[38;5;229m", // pale yellow
            TokenTypeEnum::VALUE => "\x1b[38;5;120m", // bright green (strings)
            TokenTypeEnum::NUMBER => "\x1b[38;5;220m", // gold
            TokenTypeEnum::PROPERTY => "\x1b[38;5;123m", // bright cyan-blue
            TokenTypeEnum::VARIABLE => "\x1b[38;5;217m", // bright salmon
            TokenTypeEnum::OPERATOR => "\x1b[38;5;252m",
            TokenTypeEnum::GENERIC => "\x1b[38;5;87m", // bright cyan
            TokenTypeEnum::ATTRIBUTE => "\x1b[38;5;186m",
            TokenTypeEnum::COMMENT => "\x1b[38;5;248m", // light gray
            default => '',
        };
    }

    public function after(TokenType $tokenType): string
    {
        return "\x1b[39m";
    }
}
