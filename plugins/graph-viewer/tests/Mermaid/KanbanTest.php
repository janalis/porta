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

final class KanbanTest extends TestCase
{
    #[DataProvider('upstreamExamples')]
    public function testPinnedUpstreamFixturesRenderWithoutCli(string $path): void
    {
        $source = \file_get_contents($path);
        $this->assertIsString($source);
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
        foreach (new \GlobIterator(__DIR__.'/../fixtures/mermaid/kanban/*.mmd') as $file) {
            yield [$file->getPathname()];
        }
    }

    public function testCancellationNeverPublishesPartialBoard(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "kanban\nRoot\n".\str_repeat("  Card\n", times: 3000),
            static function (): never {
                self::fail('Cancelled Kanban published');
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
    public function testNativeExportEscapesMetadataAndDrawsColumns(Format $format): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            "kanban\nTodo\n  task[<script>]@{ assigned: 'User', ticket: 'javascript:alert(1)', priority: High }\nDone",
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
            $this->assertStringContainsString('>javascript:alert(1)</text>', $result, 'the ticket is inert text at the card foot');
            $this->assertStringNotContainsString('href=', $result);
            $this->assertStringNotContainsString('<script>', $result);

            return;
        }
        $this->assertStringStartsWith("\x89PNG", $result);
        $image = \imagecreatefromstring($result);
        $this->assertInstanceOf(\GdImage::class, $image);
        // The first column: the default theme's `cScale2` lightened by 10, as pinned.
        $this->assertSame(0xe8_ffb9, \imagecolorat($image, x: 100, y: 40));
    }

    /** @return iterable<array{Format}> */
    public static function exportFormats(): iterable
    {
        yield [Format::Svg];
        yield [Format::Png];
    }
}
