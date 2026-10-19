<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Render\GraphCellGrid;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\ImageViewer\ZoomPanModel;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Bounded polygonal outlines shared by the native SVG and raster backends. */
final class MermaidOutline
{
    /** @return list<MermaidMark> */
    public static function marks(GraphNode $node, string $shape): array
    {
        if (FlowchartShapes::custom(self::canonical($shape))) {
            return FlowchartGlyphs::marks($node, self::canonical($shape));
        }

        return match (true) {
            \str_starts_with($shape, 'wardley:accelerator:') || \str_starts_with($shape, 'wardley:deaccelerator:') => WardleySymbols::marks($node, $shape),
            \str_starts_with($shape, 'journey-face:') => JourneyFaces::marks($node, $shape),
            \str_starts_with($shape, 'pie:') => [new MermaidMark(
                'polygon',
                PieSymbols::points($node, $shape),
                stroke: $node->color ?? '#ffffff',
                fill: $node->fillColor ?? '#ececff',
                strokeWidth: $node->strokeWidth,
                opacity: $node->opacity,
            )],
            \str_starts_with($shape, 'venn-') => VennSymbols::marks($node, $shape),
            \str_starts_with($shape, 'arch-') => ArchitectureSymbols::marks($node, $shape),
            \str_starts_with($shape, 'uc-actor:') => UsecaseSymbols::actor($node, $shape),
            \str_starts_with($shape, 'uc-json:') => UsecaseSymbols::table($node, $shape),
            'uc-business' === $shape => UsecaseSymbols::business($node),
            'c4-person' === $shape => C4Symbols::person($node),
            'mindmap-plain' === $shape => self::plain($node),
            \str_starts_with($shape, 'git-tag') => GitMarkers::tags($node, $shape),
            \str_starts_with($shape, 'tv-icon:') => TreeViewIcons::marks($node, $shape),
            \str_starts_with($shape, 'fc-icon:') || \str_starts_with($shape, 'fc-image:') => FlowchartMedia::marks($node, $shape),
            \str_starts_with($shape, 'seq-') => SequenceSymbols::marks($node, $shape),
            'c4-queue' === $shape => C4Symbols::queue($node),
            default => self::ordinary($node, $shape),
        };
    }

    /** @return list<MermaidMark> */
    private static function ordinary(GraphNode $node, string $shape): array
    {
        $cardShape = $shape;
        $shape = self::canonical($shape);
        if ('text' === $shape || \str_starts_with($shape, 'rotated:')) {
            return \str_starts_with($shape, 'rotated:') ? self::backdrop($node, $shape) : [];
        }
        $points = self::points($node, $shape);
        $marks = [new MermaidMark(
            'polygon',
            $points,
            stroke: $node->color ?? '#333333',
            fill: $node->fillColor ?? '#ececff',
            dashed: $node->dashed,
            strokeWidth: $node->strokeWidth,
            dashes: $node->dashes,
        )];
        $left = $node->x - ($node->w / 2);
        $right = $node->x + ($node->w / 2);
        $top = $node->y - ($node->h / 2);
        $bottom = $node->y + ($node->h / 2);
        if (\str_starts_with($cardShape, 'rq-card:')) {
            // A requirement's header lines sit over a divider, as pinned `requirementBox` draws it.
            $marks[] = self::line($node, [[$left, $top + 2 + (2 * (int) \substr($cardShape, offset: 8))], [$right, $top + 2 + (2 * (int) \substr($cardShape, offset: 8))]]);
        }
        if (\str_starts_with($cardShape, 'er-card:') || \str_starts_with($cardShape, 'rd-card:')) {
            \array_push($marks, ...ErCard::dividers($node, $cardShape), ...ClassMembers::underlines($node, $cardShape));
        }
        if (\str_starts_with($cardShape, 'git:')) {
            [$type, $custom, $inner] = GitMarkers::parse($cardShape);
            foreach (GitMarkers::points($node, $type, $custom) as $glyph) {
                $marks[] = new MermaidMark('polyline', $glyph, stroke: $inner, strokeWidth: 0.2);
            }
        }
        if (\str_starts_with($cardShape, 'wardley:')) {
            \array_push($marks, ...WardleySymbols::marks($node, $cardShape));
        }
        if ('subroutine' === $shape) {
            foreach ([$left + 1.0, $right - 1.0] as $x) {
                $marks[] = self::line($node, [[$x, $top], [$x, $bottom]]);
            }
        }
        if ('lin-doc' === $shape) {
            $marks[] = self::line($node, [[$left + 1.0, $top], [$left + 1.0, $bottom - ($node->h / 8)]]);
        }
        if ('doublecircle' === $shape) {
            $marks[] = self::line($node, self::ellipse($left + 0.8, $top + 0.8, $right - 0.8, $bottom - 0.8));
        }
        if ('state-end' === $shape) {
            $marks[] = new MermaidMark('polygon', self::ellipse($left + 0.8, $top + 0.8, $right - 0.8, $bottom - 0.8), fill: $node->color ?? '#222222', strokeWidth: 0.0);
        }
        if ('cylinder' === $shape) {
            $marks[] = self::line($node, self::ellipse($left, $top, $right, $top + 1.6));
        }

        return $marks;
    }

    /**
     * A rotated label's backdrop: its text's box in the node's fill, turned with it.
     *
     * @return list<MermaidMark>
     */
    private static function backdrop(GraphNode $node, string $shape): array
    {
        [, $angle, $x, $y] = \explode(':', $shape) + ['', '0', '0', '0'];
        $lines = \explode("\n", $node->drawLabel ?? $node->label);
        $width = (float) (\max(\array_map(AnsiUtils::visibleWidth(...), $lines)) + 1) / 2;
        $height = (float) \count($lines);
        if (null === $node->fillColor || 'none' === $node->fillColor || !\is_numeric($angle) || !\is_numeric($x) || !\is_numeric($y)) {
            return [];
        }
        $radians = \deg2rad((float) $angle);
        $corners = [];
        foreach ([[-$width, -$height], [$width, -$height], [$width, $height], [-$width, $height]] as [$along, $across]) {
            $corners[] = [(float) $x + ($along * \cos($radians)) - ($across * \sin($radians)), (float) $y + ($along * \sin($radians)) + ($across * \cos($radians))];
        }

        return [new MermaidMark('polygon', $corners, stroke: 'none', fill: $node->fillColor, strokeWidth: 0.0)];
    }

    /**
     * Pinned default mindmap nodes: a rounded box in the section color, a line along its foot.
     *
     * @return list<MermaidMark>
     */
    private static function plain(GraphNode $node): array
    {
        $bottom = $node->y + ($node->h / 2);

        return [
            new MermaidMark('polygon', self::points($node, 'rounded'), stroke: 'none', fill: $node->fillColor ?? '#ececff', strokeWidth: 0.0),
            new MermaidMark('polyline', [[$node->x - ($node->w / 2), $bottom], [$node->x + ($node->w / 2), $bottom]], stroke: $node->color ?? '#333333'),
        ];
    }

    /** @param list<array{float, float}> $points */
    private static function line(GraphNode $node, array $points): MermaidMark
    {
        return new MermaidMark('polyline', $points, stroke: $node->color ?? '#333333', dashed: $node->dashed, strokeWidth: $node->strokeWidth, dashes: $node->dashes);
    }

    /** @return list<array{float, float}> */
    public static function points(GraphNode $node, string $shape): array
    {
        if (FlowchartShapes::custom(self::canonical($shape))) {
            return FlowchartShapeGeometry::points($node, self::canonical($shape));
        }

        return match (true) {
            \str_starts_with($shape, 'pie:') => PieSymbols::points($node, $shape),
            \str_starts_with($shape, 'block-arrow:') => BlockArrows::points($node, $shape),
            \str_starts_with($shape, 'wardley:accelerator:') || \str_starts_with($shape, 'wardley:deaccelerator:') => WardleySymbols::arrow($node, \explode(':', $shape)[1]),
            default => self::ordinaryPoints($node, $shape),
        };
    }

    /** @return list<array{float, float}> */
    private static function ordinaryPoints(GraphNode $node, string $shape): array
    {
        $left = $node->x - ($node->w / 2);
        $right = $node->x + ($node->w / 2);
        $top = $node->y - ($node->h / 2);
        $bottom = $node->y + ($node->h / 2);
        $inset = \min(2.0, $node->w / 4);
        $corner = \min($inset, $node->h / 3);
        // Pinned slants run half the height across, a hexagon's points two sevenths of it.
        $slant = \min($node->h / 2, $node->w / 4);
        $point = \min(($node->h * 2) / 7, $node->w / 4);

        return match (self::canonical($shape)) {
            'notch-rect' => [[$left + \min(1.2, $corner), $top], [$right, $top], [$right, $bottom], [$left, $bottom], [$left, $top + \min(1.2, $corner)]],
            'sl-rect' => [[$left, $top + ($node->h / 3)], [$right, $top], [$right, $bottom], [$left, $bottom]],
            'tri' => [[$node->x, $top], [$right, $bottom], [$left, $bottom]],
            'flip-tri' => [[$left, $top], [$right, $top], [$node->x, $bottom]],
            'diamond' => [[$node->x, $top], [$right, $node->y], [$node->x, $bottom], [$left, $node->y]],
            'circle', 'doublecircle', 'ellipse', 'state-end' => self::ellipse($left, $top, $right, $bottom),
            'stadium' => self::rounded($left, $top, $right, $bottom, \min($node->w, $node->h) / 2),
            'rounded' => self::rounded($left, $top, $right, $bottom, radius: 1.0),
            'hexagon' => [[$left + $point, $top], [$right - $point, $top], [$right, $node->y], [$right - $point, $bottom], [$left + $point, $bottom], [$left, $node->y]],
            'lean-r' => [[$left + $slant, $top], [$right, $top], [$right - $slant, $bottom], [$left, $bottom]],
            'lean-l' => [[$left, $top], [$right - $slant, $top], [$right, $bottom], [$left + $slant, $bottom]],
            'trap-b' => [[$left + $slant, $top], [$right - $slant, $top], [$right, $bottom], [$left, $bottom]],
            'trap-t' => [[$left, $top], [$right, $top], [$right - $slant, $bottom], [$left + $slant, $bottom]],
            'asymmetric' => [[$left, $top], [$right, $top], [$right, $bottom], [$left, $bottom], [$left + $inset, $node->y]],
            'fishhead' => self::fishhead($node),
            'cloud', 'bang' => self::lobes($node, $shape),
            'cylinder' => self::cylinder($left, $top, $right, $bottom),
            'lin-doc' => self::document($node),
            'uc-package' => [[$left, $top], [$right - $corner, $top], [$right, $top + $corner], [$right, $bottom], [$left, $bottom]],
            default => [[$left, $top], [$right, $top], [$right, $bottom], [$left, $bottom]],
        };
    }

    /** @return list<array{float, float}> */
    private static function document(GraphNode $node): array
    {
        $left = $node->x - ($node->w / 2);
        $right = $node->x + ($node->w / 2);
        $top = $node->y - ($node->h / 2);
        $bottom = $node->y + ($node->h / 2);
        $amplitude = $node->h / 8;
        $points = [[$left, $top], [$left, $bottom - $amplitude]];
        for ($step = 1; $step <= 32; ++$step) {
            $fraction = $step / 32;
            $points[] = [$left + ($node->w * $fraction), $bottom - $amplitude + ($amplitude * \sin(2 * \M_PI * $fraction))];
        }
        $points[] = [$right, $top];

        return $points;
    }

    public static function contains(GraphNode $node, string $shape, float $x, float $y): bool
    {
        $polygon = self::points($node, $shape);
        $inside = false;
        $previous = $polygon[\count($polygon) - 1];
        foreach ($polygon as [$bx, $by]) {
            [$ax, $ay] = $previous;
            $cross = (($x - $ax) * ($by - $ay)) - (($y - $ay) * ($bx - $ax));
            if (\abs($cross) < 0.000_001 && $x >= \min($ax, $bx) && $x <= \max($ax, $bx) && $y >= \min($ay, $by) && $y <= \max($ay, $by)) {
                return true;
            }
            if ($ay > $y !== $by > $y && $x < (((($bx - $ax) * ($y - $ay)) / ($by - $ay)) + $ax)) {
                $inside = !$inside;
            }
            $previous = [$bx, $by];
        }

        return $inside;
    }

    public static function nodeAt(MermaidScene $scene, ZoomPanModel $view, int $cols, int $rows, int $row, int $col): ?int
    {
        $rect = $view->visibleRect($cols, 2 * $rows);
        $scale = $view->effectiveScale($cols, 2 * $rows);
        $x = $rect['srcX'] + (($col + 0.5 - $rect['padCols']) / $scale);
        $y = $rect['srcY'] + (((2 * $row) + 1 - $rect['padHalfRows']) / $scale);

        $id = self::pieAt($scene, $x, $y) ?? self::markerAt($scene, $view, $cols, $rows, $row, $col) ?? GraphRenderer::nodeShownAt($scene->selectionGraph, $view, $cols, $rows, $row, $col);
        if (null === $id) {
            return null;
        }

        return 'point' === ($scene->shapes[$id] ?? 'rect') || self::contains($scene->graph->nodes[$id], $scene->shapes[$id] ?? 'rect', $x, $y) ? $id : null;
    }

    private static function pieAt(MermaidScene $scene, float $x, float $y): ?int
    {
        foreach (\array_reverse($scene->selectionGraph->nodes, preserve_keys: true) as $node) {
            $shape = $scene->shapes[$node->id] ?? 'rect';
            if (\str_starts_with($shape, 'pie:') && self::contains($node, $shape, $x, $y)) {
                return $node->id;
            }
        }

        return null;
    }

    private static function markerAt(MermaidScene $scene, ZoomPanModel $view, int $cols, int $rows, int $row, int $col): ?int
    {
        $grid = new GraphCellGrid($cols, $rows, $view, null);
        $hit = null;
        $most = 0.0;
        foreach ($scene->selectionGraph->nodes as $node) {
            if ('point' !== ($scene->shapes[$node->id] ?? 'rect')) {
                continue;
            }
            $coverage = $grid->coverage($node, $col, $row);
            if ($coverage > 0.0 && $coverage >= $most) {
                [$hit, $most] = [$node->id, $coverage];
            }
        }

        return $hit;
    }

    /** @return array{float, float} */
    public static function boundary(GraphNode $node, string $shape, float $towardX, float $towardY): array
    {
        $dx = $towardX - $node->x;
        $dy = $towardY - $node->y;
        if (0.0 === $dx && 0.0 === $dy) {
            $dy = -1.0;
        }
        $points = self::points($node, $shape);
        $previous = $points[\count($points) - 1];
        $nearest = \INF;
        foreach ($points as [$bx, $by]) {
            [$ax, $ay] = $previous;
            $ex = $bx - $ax;
            $ey = $by - $ay;
            $denominator = ($dx * $ey) - ($dy * $ex);
            if (\abs($denominator) > 0.000_001) {
                $distance = ((($ax - $node->x) * $ey) - (($ay - $node->y) * $ex)) / $denominator;
                $along = ((($ax - $node->x) * $dy) - (($ay - $node->y) * $dx)) / $denominator;
                if ($distance >= 0.0 && $along >= 0.0 && $along <= 1.0) {
                    $nearest = \min($nearest, $distance);
                }
            }
            $previous = [$bx, $by];
        }
        $distance = \is_finite($nearest) ? $nearest : 0.0;

        return [$node->x + ($dx * $distance), $node->y + ($dy * $distance)];
    }

    /** @return list<array{float, float}> */
    private static function fishhead(GraphNode $node): array
    {
        $points = [];
        for ($index = 0; $index <= 24; ++$index) {
            $angle = (-\M_PI / 2) + ((\M_PI * $index) / 24);
            $points[] = [$node->x - ($node->w / 2) + ($node->w * \cos($angle)), $node->y + (($node->h / 2) * \sin($angle))];
        }

        return $points;
    }

    public static function canonical(string $shape): string
    {
        if (\str_starts_with($shape, 'git:')) {
            [$type, $custom] = GitMarkers::parse($shape);

            $shape = 'HIGHLIGHT' === ($custom ?? $type) ? 'rect' : 'circle';
        }
        if (\str_starts_with($shape, 'wardley:')) {
            $shape = 'circle';
        }
        $shape = match (true) {
            \str_starts_with($shape, 'rd-card:') => 'rounded',
            \str_starts_with($shape, 'kanban-card:') => 'kanban-card',
            default => $shape,
        };

        return match ($shape) {
            'c4-person' => 'rect',
            'card', 'notched-rectangle' => 'notch-rect',
            'manual-input', 'sloped-rectangle' => 'sl-rect',
            'triangle', 'extract' => 'tri',
            'flipped-triangle', 'manual-file' => 'flip-tri',
            'diam', 'decision', 'question' => 'diamond',
            'pill', 'terminal' => 'stadium',
            'lean-right', 'in-out' => 'lean-r',
            'lean-left', 'out-in' => 'lean-l',
            'priority', 'trapezoid-bottom', 'trapezoid' => 'trap-b',
            'manual', 'trapezoid-top', 'inv-trapezoid' => 'trap-t',
            'circ', 'point' => 'circle',
            'dbl-circ', 'double-circle' => 'doublecircle',
            'cyl', 'database', 'db' => 'cylinder',
            'subproc', 'subprocess', 'fr-rect', 'framed-rectangle' => 'subroutine',
            'round', 'roundedRect', 'event', 'c4-queue' => 'rounded',
            'lined-document' => 'lin-doc',
            'uc-business' => 'ellipse',
            'hex', 'prepare' => 'hexagon',
            'odd' => 'asymmetric',
            default => FlowchartShapes::canonical($shape),
        };
    }

    /** @return list<array{float, float}> */
    private static function lobes(GraphNode $node, string $shape): array
    {
        $points = [];
        $steps = 'bang' === $shape ? 32 : 128;
        for ($index = 0; $index <= $steps; ++$index) {
            $angle = (2 * \M_PI * $index) / $steps;
            $radius = match (true) {
                'cloud' === $shape => 0.9 + (0.1 * \cos(8 * $angle)),
                0 === ($index % 2) => 1.0,
                default => 0.78,
            };
            $points[] = [$node->x + (($node->w / 2) * $radius * \cos($angle)), $node->y + (($node->h / 2) * $radius * \sin($angle))];
        }

        return $points;
    }

    /** @return list<array{float, float}> */
    public static function ellipse(float $left, float $top, float $right, float $bottom): array
    {
        $points = [];
        for ($index = 0; $index <= 48; ++$index) {
            $angle = (2 * \M_PI * $index) / 48;
            $points[] = [(($left + $right) / 2) + ((($right - $left) / 2) * \cos($angle)), (($top + $bottom) / 2) + ((($bottom - $top) / 2) * \sin($angle))];
        }

        return $points;
    }

    /** @return list<array{float, float}> */
    private static function rounded(float $left, float $top, float $right, float $bottom, float $radius): array
    {
        $radius = \min($radius, ($right - $left) / 2, ($bottom - $top) / 2);
        $points = [];
        foreach ([[$right - $radius, $top + $radius, -90], [$right - $radius, $bottom - $radius, 0], [$left + $radius, $bottom - $radius, 90], [$left + $radius, $top + $radius, 180]] as [
            $x,
            $y,
            $start,
        ]) {
            for ($index = 0; $index <= 8; ++$index) {
                $angle = \deg2rad($start + ((90 * $index) / 8));
                $points[] = [$x + ($radius * \cos($angle)), $y + ($radius * \sin($angle))];
            }
        }

        return $points;
    }

    /** @return list<array{float, float}> */
    private static function cylinder(float $left, float $top, float $right, float $bottom): array
    {
        $points = [];
        $center = ($left + $right) / 2;
        foreach ([[$top + 0.8, 180], [$bottom - 0.8, 0]] as [$y, $start]) {
            for ($index = 0; $index <= 24; ++$index) {
                $angle = \deg2rad($start + ((180 * $index) / 24));
                $points[] = [$center + ((($right - $left) / 2) * \cos($angle)), $y + (0.8 * \sin($angle))];
            }
        }

        return $points;
    }
}
