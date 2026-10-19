<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Which elements the pinned color themes (redux-color, redux-dark-color) paint from their palettes. */
enum MermaidColorSlots
{
    case None;
    case Clusters;
    /** Frames take only the slot border: composite states keep their own body color. */
    case FrameStrokes;
    case Nodes;

    /** An unstyled element's slot tokens; authored colors, applied afterwards, still win. */
    public static function stroke(?string $color, int $slot): string
    {
        return $color ?? '@slot:stroke:'.$slot;
    }

    public static function fill(?string $color, int $slot): string
    {
        return $color ?? '@slot:fill:'.$slot;
    }
}
