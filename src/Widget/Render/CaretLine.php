<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * Whether a rendered gutter row is the line the caret sits on (so its number is
 * highlighted). Replaces the former `bool $isCursorLine` flag on
 * {@see EditorRowAssembler}'s gutter cell.
 */
enum CaretLine
{
    case Current;
    case Other;
}
