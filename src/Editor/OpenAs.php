<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Which pane a file opens in: whatever viewer claims it, or the TEXT editor
 * regardless — the "Open as Text" escape, for reading the XML behind an SVG,
 * the DOT behind a graph, or the bytes of anything else a viewer would swallow.
 */
enum OpenAs
{
    case Viewer;
    case Text;
}
