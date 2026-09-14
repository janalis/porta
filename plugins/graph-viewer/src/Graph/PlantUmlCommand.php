<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

use Symfony\Component\Process\ExecutableFinder;

/**
 * How to invoke PlantUML, which arrives in three shapes on real machines: a
 * `plantuml` launcher on the PATH (what package managers install), an explicit
 * path to one, or the bare `plantuml.jar` most people actually download — and
 * that last one has to be handed to `java -jar`.
 *
 * Pure and static so the argv is testable without a JVM anywhere near the
 * suite; {@see PlantUmlEngine} does the running.
 */
final class PlantUmlCommand
{
    /** PlantUML's natural size: its `dpi` skinparam defaults to 96, where dot's is 72. */
    public const int BASE_DPI = 96;

    /**
     * The tool to run, or null when none is installed: the configured path (a
     * `.jar` included), else `plantuml` on the PATH.
     */
    public static function tool(?string $configured): ?string
    {
        $path = \trim((string) $configured);
        if ('' !== $path) {
            return $path;
        }

        return new ExecutableFinder()->find('plantuml');
    }

    /**
     * The full argv for one render at $dpi, reading the diagram from stdin and
     * writing PNG to stdout.
     *
     * `-failfast2` checks the whole document BEFORE drawing, so a syntax error
     * exits non-zero instead of producing an image of the error message — which
     * is what PlantUML does by default, and would otherwise reach the pane as a
     * perfectly valid picture of a mistake.
     *
     * @return list<string>
     */
    public static function argv(string $tool, int $dpi): array
    {
        // A jar cannot be executed; the launcher scripts are just this line.
        $prefix = \str_ends_with(\strtolower($tool), '.jar') ? ['java', '-jar', $tool] : [$tool];

        return [...$prefix, '-tpng', '-pipe', '-failfast2', '-charset', 'UTF-8', '-Sdpi='.$dpi];
    }
}
