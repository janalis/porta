<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\RendersExactWidth;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The graph pane may not render a row wider than the pane it was given.
 *
 * This plugin's share of {@see \Porta\Tests\Unit\Widget\NarrowPaneRenderTest},
 * which is a core test over core widgets and had therefore never seen this
 * widget. The same sweep over the git plugin found three live crashes; this one
 * found none, which is worth having written down rather than assumed.
 *
 * The sweep's own vacuity guard is what makes that a real answer: a widget with
 * nothing to draw renders no rows, and a loop over no rows passes by having
 * nothing to look at.
 */
final class NarrowGraphPaneRenderTest extends TestCase
{
    use RendersExactWidth;

    /** An empty pane, still waiting for anything to draw. */
    private static function blank(): GraphViewerWidget
    {
        return new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), new FakeLayoutEngine(), scheduler: new FakeScheduler());
    }

    /**
     * A pane with a laid-out graph. The debounce is fired so the bitmap
     * exists — without it the pane is still waiting and draws its placeholder,
     * which is a different assembler and the one {@see blank()} covers.
     */
    private static function drawn(): GraphViewerWidget
    {
        $scheduler = new FakeScheduler();
        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), new FakeLayoutEngine(), scheduler: $scheduler);
        $widget->show('/p/g.dot', 'digraph G { A -> B [label="calls"]; B -> C; }');
        if ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }

        return $widget;
    }

    /** @return iterable<string, array{AbstractWidget}> */
    public static function graphPanes(): iterable
    {
        yield 'graph' => [self::drawn()];

        $focused = self::drawn();
        $focused->setFocused(true); // the focus frame is its own row assembler
        yield 'graph, focused' => [$focused];

        yield 'no graph' => [self::blank()];
    }

    #[DataProvider('graphPanes')]
    public function testTheGraphPaneFitsEveryWidthDownToOne(AbstractWidget $widget): void
    {
        self::assertFitsEveryWidth($widget);
    }
}
