<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Render\Format;

/**
 * Turns DOT source into a {@see GraphLayout} asynchronously — the seam the
 * graph viewer depends on, so tests (and any future non-Graphviz engine)
 * substitute a fake without a dot binary anywhere near the suite.
 *
 * The GEOMETRY half of the diagram tab: everything here is something only
 * `dot` can answer, which is why it extends {@see DiagramEngine} rather than
 * being it. A tool that can only draw (PlantUML) implements the supertype.
 */
interface LayoutEngine extends DiagramEngine
{
    /**
     * Lay out $dot; exactly one callback fires — unless a newer layout()
     * supersedes this one first (latest wins, the superseded call goes silent).
     *
     * @param \Closure(GraphLayout): void $onDone
     * @param \Closure(string): void      $onError receives dot's stderr (or a parse failure message)
     */
    public function layout(string $dot, \Closure $onDone, \Closure $onError): void;

    /**
     * Render $dot to $outPath.
     *
     * @param \Closure(?string): void $onDone null on success, the error text on failure
     */
    public function export(string $dot, Format $format, string $outPath, \Closure $onDone): void;
}
