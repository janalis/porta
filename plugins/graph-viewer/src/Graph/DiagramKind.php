<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

use Porta\Support\ProjectPaths;

/**
 * Which tool draws a diagram tab, decided by the file's extension. Pure — it
 * never crosses `.porta.json`; the buffer keeps carrying its source as text and
 * the 'graph' pane id still means "a diagram tab", so nothing else has to know.
 *
 * The difference that matters downstream is GEOMETRY: `Dot` has a layout (nodes,
 * edges, hit-testing, counts, the readable cell rendering); `PlantUml` and
 * `Mermaid` currently supply pictures without node geometry.
 */
enum DiagramKind
{
    case Dot;
    case PlantUml;
    case Mermaid;

    /** The extensions the graph viewer claims for each tool. */
    public function extensions(): array
    {
        return match ($this) {
            self::Dot => ['dot', 'gv'],
            self::Mermaid => ['mmd', 'mermaid'],
            self::PlantUml => ['puml', 'plantuml', 'pu', 'iuml', 'wsd'],
        };
    }

    /** Whether this tool also answers for geometry, not just pixels. */
    public function hasLayout(): bool
    {
        return self::Dot === $this;
    }

    /** The kind a path names, or null when no diagram tool claims it. */
    public static function forPath(string $path): ?self
    {
        $extension = ProjectPaths::extension($path);
        foreach (self::cases() as $kind) {
            if (\in_array($extension, $kind->extensions(), strict: true)) {
                return $kind;
            }
        }

        return null;
    }
}
