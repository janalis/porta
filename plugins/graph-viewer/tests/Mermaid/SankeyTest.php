<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\PngBackend;
use Janalis\Viz\Draw\SvgBackend;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class SankeyTest extends TestCase
{
    #[DataProvider('upstreamExamples')]
    public function testPinnedUpstreamExamplesRenderWithoutCli(string $source): void
    {
        $result = NativeFixtureRows::render($source, width: 30);
        $this->assertNotEmpty($result->rows);
        $this->assertCount(\count($result->rows), $result->origins);
        foreach ($result->rows as $row) {
            $this->assertLessThanOrEqual(30, AnsiUtils::visibleWidth($row));
        }
    }

    /** @return iterable<array{string}> */
    public static function upstreamExamples(): iterable
    {
        yield [(string) \file_get_contents(__DIR__.'/../fixtures/mermaid/sankey/should-render-a-simple-example.mmd')];
        yield ["sankey\n".(string) \file_get_contents(__DIR__.'/../fixtures/mermaid/sankey/energy.csv')];
    }

    public function testCancellationNeverPublishesAnIncompleteWeightedScene(): void
    {
        $scheduler = new FakeScheduler();
        $source = "sankey\n";
        for ($index = 0; $index < 3000; ++$index) {
            $source .= $index.','.($index + 1).",1\n";
        }
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            $source,
            static function (): never {
                self::fail('Cancelled Sankey scene published');
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertNotEmpty($scheduler->scheduled);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
    }

    #[DataProvider('exportFormats')]
    public function testExportsContainFilledFlowBandsAndEscapeSourceText(Format $format): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            "sankey\n<script>,Target,10",
            $format,
            static function (string $value) use (&$result): void {
                $result = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertIsString($result);
        if (Format::Svg === $format) {
            $this->assertStringContainsString('&lt;script&gt;', $result);
            $this->assertStringContainsString('<stop offset="0" stop-color="#4e79a7" stop-opacity="0.502"/>', $result, 'pinned links default to a gradient, half opaque');
            $this->assertStringContainsString('fill="url(#gradient-', $result);
            $this->assertStringNotContainsString('<script>', $result);
            $work = SvgBackend::render(new Drawing(10.0, 2.0, [new Mark(
                MarkKind::Polygon,
                [[2.0, 0.0], [8.0, 0.0], [8.0, 2.0], [2.0, 2.0]],
                strokeWidth: 0.0,
                gradient: ['#000000', '#ffffff'],
            )]));
            \iterator_to_array($work);
            $this->assertMatchesRegularExpression('/x1="[\d.]+" y1="0" x2="[\d.]+" y2="0"/', $work->getReturn());
            \preg_match('/x1="([\d.]+)" y1="0" x2="([\d.]+)"/', $work->getReturn(), $span);
            $this->assertGreaterThan((float) $span[1], (float) $span[2], 'the gradient spans the polygon left to right');

            return;
        }
        $this->assertStringStartsWith("\x89PNG", $result);
        $image = \imagecreatefromstring($result);
        $this->assertInstanceOf(\GdImage::class, $image);
        // A flow band, blended between its ends' colors at the pinned 50% link opacity, over white.
        $this->assertSame(0xe2_c3a5, \imagecolorat($image, x: 500, y: 500));
        $drawing = new Drawing(10.0, 2.0, [
            new Mark(MarkKind::Polygon, [[0.0, 0.0], [10.0, 0.0], [10.0, 2.0], [0.0, 2.0]], strokeWidth: 0.0, gradient: ['#000000ff', '#ffffffff']),
            // A zero-width band crosses no edge it can interpolate, and draws nothing.
            new Mark(MarkKind::Polygon, [[5.0, 0.0], [5.0, 1.0], [5.0, 2.0]], strokeWidth: 0.0, gradient: ['#ff0000ff', '#ff0000ff']),
        ]);
        $work = new PngBackend()->render($drawing);
        \iterator_to_array($work);
        $ramp = \imagecreatefromstring($work->getReturn());
        $this->assertInstanceOf(\GdImage::class, $ramp);
        $this->assertSame(
            [0x00_0000, 0x80_8080, 0xfc_fcfc],
            [\imagecolorat($ramp, x: 0, y: 10), \imagecolorat($ramp, x: 50, y: 10), \imagecolorat($ramp, x: 99, y: 10)],
            'the fill runs left to right',
        );
        $work = new PngBackend()->render(new Drawing(10.0, 2.0, [new Mark(MarkKind::Polygon, [[0.0, 0.0], [10.0, 0.0], [5.0, 2.0]], strokeWidth: 0.0, gradient: ['#000000ff', '#000000ff'])]));
        \iterator_to_array($work);
        $wedge = \imagecreatefromstring($work->getReturn());
        $this->assertInstanceOf(\GdImage::class, $wedge);
        $this->assertSame(0x00_0000, \imagecolorat($wedge, x: 20, y: 4), 'the closing edge bounds the fill too');
    }

    /** @return iterable<array{Format}> */
    public static function exportFormats(): iterable
    {
        yield [Format::Svg];
        yield [Format::Png];
    }
}
