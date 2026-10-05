<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Widget\EdgeRuleWidget;
use Porta\Widget\SeparatorWidget;
use Porta\Widget\WorkspaceEdge;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The two widget shapes the layout builders mint — an id'd
 * vertically-expanding container and a fixed-flex pane separator — which
 * {@see WorkspaceLayout} (the compiled chrome) and {@see ViewRegions} (the
 * runtime-minted view regions) each hand-rolled as private pairs.
 */
final class LayoutWidgets
{
    public static function container(string $id, Style $style): ContainerWidget
    {
        $container = new ContainerWidget();
        $container->setId($id);
        $container->setStyle($style);
        $container->expandVertically(true);

        return $container;
    }

    /** The row across the workspace's $edge, as tall as the screen affords: one row, or none. */
    public static function edgeRule(Styles $styles, ScreenDensity $density, WorkspaceEdge $edge, string $id): EdgeRuleWidget
    {
        $rule = new EdgeRuleWidget($density, $edge, $styles);
        $rule->setId($id)->setStyle(new Style(flex: 0));

        return $rule;
    }

    public static function separator(Styles $styles, string $id): SeparatorWidget
    {
        $separator = new SeparatorWidget($styles);
        $separator->setId($id)->setStyle(new Style(flex: 0));

        return $separator;
    }
}
