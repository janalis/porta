<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Janalis\Viz\Mermaid\MermaidScene;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\Help\Fence\FenceResult;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Support\AnsiText;
use Porta\Ui\Theme\Styles;

/** Fitted graph plus wrapped semantic labels; narrow panes never lose an edge or label. */
final class MermaidSceneRows
{
    /** @return \Generator<int, null, mixed, FenceResult> */
    public static function rows(MermaidScene $scene, int $width, Styles $styles): \Generator
    {
        $width = \max(1, \min(200, $width));
        $height = \max(1, \min(24, (int) \ceil($scene->graph->height / 2)));
        $view = new ZoomPanModel((int) \ceil($scene->graph->width), (int) \ceil($scene->graph->height), [1.0]);
        $rows = GraphRenderer::render($scene->graph, $view, null, $width, $height, $styles);
        $origins = [];
        $rect = $view->visibleRect($width, 2 * $height);
        $scale = $view->effectiveScale($width, 2 * $height);
        foreach ($rows as $index => $_row) {
            $sourceRow = (int) \floor(($rect['srcY'] + (((2 * $index) - $rect['padHalfRows']) / $scale)) / 2);
            $origins[] = $scene->origins[\max(0, $sourceRow)] ?? 0;
            yield null;
        }
        foreach ($scene->legend as $item) {
            foreach (AnsiText::wrap($item['text'], $width) as $row) {
                $rows[] = $row;
                $origins[] = $item['origin'];
                yield null;
            }
        }

        return FenceResult::rows($rows, $origins);
    }
}
