<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

/**
 * Whether {@see EditorRenderer::renderContentLine()} splices the zero-width
 * cursor marker into the sliced content line. Replaces the former
 * `bool $withCursor` render flag.
 */
enum CursorVisibility
{
    case Shown;
    case Hidden;
}
