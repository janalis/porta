<?php

declare(strict_types=1);

namespace Porta\Graph;

/**
 * Which tool draws a diagram tab, decided by the file's extension. Pure — it
 * never crosses `.porta.json`; the buffer keeps carrying its source as text and
 * `isGraph()` still means "a diagram tab", so nothing else has to know.
 *
 * The difference that matters downstream is GEOMETRY: `Dot` has a layout (nodes,
 * edges, hit-testing, counts, the readable cell rendering), `PlantUml` has a
 * picture and nothing else.
 */
enum DiagramKind
{
    case Dot;
    case PlantUml;

    /** The extensions the graph viewer claims for each tool. */
    public function extensions(): array
    {
        return match ($this) {
            self::Dot => ['dot', 'gv'],
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
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        foreach (self::cases() as $kind) {
            if (\in_array($extension, $kind->extensions(), strict: true)) {
                return $kind;
            }
        }

        return null;
    }
}
