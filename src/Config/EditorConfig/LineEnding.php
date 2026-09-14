<?php

declare(strict_types=1);

namespace Porta\Config\EditorConfig;

/**
 * An `.editorconfig` `end_of_line` value. Backed by the spec's own spelling,
 * so parsing is `tryFrom()` and an unknown value (`nel`, a typo) overrides
 * nothing.
 */
enum LineEnding: string
{
    case Lf = 'lf';
    case CrLf = 'crlf';
    case Cr = 'cr';

    /** The bytes written between lines. */
    public function bytes(): string
    {
        return match ($this) {
            self::Lf => "\n",
            self::CrLf => "\r\n",
            self::Cr => "\r",
        };
    }
}
