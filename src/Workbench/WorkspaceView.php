<?php

declare(strict_types=1);

namespace Porta\Workbench;

/**
 * The full-screen views {@see WorkspaceViews} interlocks. Adding a view means
 * adding a case — the exhaustive {@see ownsMainArea} match then fails
 * analysis until every consumer has decided how the new view behaves, the
 * Theme-enum property.
 */
enum WorkspaceView
{
    case Git;
    case Settings;
    case Welcome;

    /**
     * Whether the view owns (detaches) the main area. The Welcome modal
     * deliberately does not: it floats over the editor layout, which stays
     * mounted (rects live, panes composited) behind it — so "is the editor
     * layout detached?" queries must not count it.
     */
    public function ownsMainArea(): bool
    {
        return match ($this) {
            self::Git, self::Settings => true,
            self::Welcome => false,
        };
    }
}
