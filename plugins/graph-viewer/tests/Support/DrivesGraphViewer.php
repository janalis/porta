<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Support;

use Janalis\Viz\GraphViz\DotJsonParser;
use Janalis\Viz\Layout\GraphLayout;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The graph pane over fake engines: a layout engine for dot, a second one
 * standing in for PlantUML's renderer, and a scheduler a test fires by hand
 * where the event loop would let the bitmap debounce elapse.
 */
trait DrivesGraphViewer
{
    private FakeLayoutEngine $engine;
    private FakeLayoutEngine $plantUml;
    private FakeScheduler $scheduler;

    protected function setUp(): void
    {
        $this->engine = new FakeLayoutEngine();
        $this->plantUml = new FakeLayoutEngine();
        $this->plantUml->baseDpi = 96; // PlantUML's own 1:1
        $this->scheduler = new FakeScheduler();
    }

    private function widget(): GraphViewerWidget
    {
        return new GraphViewerWidget($this->engine, new Translator('en'), $this->plantUml, scheduler: $this->scheduler);
    }

    /** Let the bitmap debounce elapse, as the event loop would. */
    private function settle(): void
    {
        if ([] !== $this->scheduler->scheduled) {
            $this->scheduler->fire();
        }
    }

    private static function layout(string $fixture = 'small.json'): GraphLayout
    {
        return DotJsonParser::parse((string) \file_get_contents(\dirname(__DIR__, levels: 4).'/tests/Support/fixtures/graph/'.$fixture));
    }

    private static function plain(array $lines): string
    {
        return \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }
}
