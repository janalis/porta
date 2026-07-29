<?php

declare(strict_types=1);

namespace Porta\Graph;

/**
 * How the graph pane draws a graph. Backed because it crosses `.porta.json`
 * (the `graphRender` setting), the {@see \Porta\Ui\Theme\Theme} rule.
 *
 * The two are not better and worse: `Bitmap` is what the graph actually looks
 * like — dot's own splines, arrowheads and fonts — while `Cells` draws node
 * labels as real terminal text, which stays readable at a pane size where the
 * bitmap's type is a few pixels tall. `Cells` is also what paints when dot
 * cannot render a bitmap at all (no libgd) or has not answered yet.
 */
enum GraphRenderMode: string
{
    case Bitmap = 'bitmap';
    case Cells = 'cells';

    /** The configured value, defaulting to the picture for anything unrecognized. */
    public static function fromId(?string $id): self
    {
        return self::tryFrom((string) $id) ?? self::Bitmap;
    }

    public function toggled(): self
    {
        return self::Bitmap === $this ? self::Cells : self::Bitmap;
    }
}
