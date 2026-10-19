<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class TreeViewTest extends TestCase
{
    #[DataProvider('upstreamExamples')]
    public function testUpstreamExamplesRenderWithoutTheCli(string $file): void
    {
        $source = \file_get_contents($file);
        $this->assertIsString($source);
        $result = NativeFixtureRows::render($source, width: 32);
        $this->assertNotEmpty($result->rows);
        foreach ($result->rows as $row) {
            $this->assertLessThanOrEqual(32, AnsiUtils::visibleWidth($row));
        }
    }

    /** @return iterable<array{string}> */
    public static function upstreamExamples(): iterable
    {
        foreach (new \GlobIterator(__DIR__.'/../fixtures/mermaid/tree-view/*.mmd') as $file) {
            yield [$file->getPathname()];
        }
    }

    public function testCancellationDoesNotPublishPartialRows(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "treeView-beta\n".\str_repeat("file\n", times: 3000),
            static function (): never {
                self::fail('Cancelled tree view published');
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
    public function testExportsEscapeLabelsAndRenderHighlightAndConnectors(Format $format): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            "treeView-beta\nroot/\n  \"<script>\" :::highlight ## &literal;",
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
            $this->assertStringContainsString('&amp;literal;', $result);
            $this->assertStringContainsString('#ffc10726', $result);
            $this->assertStringContainsString('<polyline', $result);
            $this->assertStringNotContainsString('<script>', $result);

            return;
        }
        $this->assertStringStartsWith("\x89PNG", $result);
        $image = \imagecreatefromstring($result);
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertGreaterThan(0, \imagesx($image));
    }

    /** @return iterable<array{Format}> */
    public static function exportFormats(): iterable
    {
        yield [Format::Svg];
        yield [Format::Png];
    }
}
