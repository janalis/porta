<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * How the editor's gutter renders line numbers (the "Interface > Line numbers"
 * setting): hidden entirely, absolute (1, 2, 3…), or relative to the caret line
 * (Vim/PhpStorm "relativenumber"). Subsumes the old boolean relative toggle.
 */
enum LineNumberMode: string
{
    case Off = 'off';
    case Absolute = 'absolute';
    case Relative = 'relative';
}
