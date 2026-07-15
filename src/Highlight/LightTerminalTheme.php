<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Porta\Ui\Sgr;
use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Themes\EscapesTerminalTheme;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * 256-color light theme for terminal syntax highlighting: the dark-on-light
 * counterpart to {@see DarkTerminalTheme}, selected when the `theme` setting is
 * `light`. Token hues are shifted to darker variants so they stay legible on a
 * light background.
 */
final class LightTerminalTheme implements TerminalTheme
{
    use EscapesTerminalTheme;

    public function before(TokenType $tokenType): string
    {
        return match ($tokenType) {
            TokenTypeEnum::KEYWORD, TokenTypeEnum::LITERAL => "\x1b[38;5;91m", // purple
            TokenTypeEnum::TYPE => "\x1b[38;5;130m", // amber
            TokenTypeEnum::VALUE => "\x1b[38;5;28m", // green (strings)
            TokenTypeEnum::NUMBER => "\x1b[38;5;166m", // orange
            TokenTypeEnum::PROPERTY => "\x1b[38;5;25m", // blue
            TokenTypeEnum::VARIABLE => "\x1b[38;5;131m", // brick
            TokenTypeEnum::OPERATOR => "\x1b[38;5;242m",
            TokenTypeEnum::GENERIC => "\x1b[38;5;30m", // cyan
            TokenTypeEnum::ATTRIBUTE => "\x1b[38;5;100m",
            TokenTypeEnum::COMMENT => "\x1b[38;5;245m", // gray
            default => '',
        };
    }

    public function after(TokenType $tokenType): string
    {
        return Sgr::DEFAULT_FG;
    }
}
