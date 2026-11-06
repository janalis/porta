<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help\Fence;

use PHPUnit\Framework\TestCase;
use Porta\Help\Fence\FenceRenderQueue;
use Porta\Help\Fence\FenceResult;
use Porta\Help\Fence\FenceSession;
use Porta\Help\Fence\MarkdownFenceRegistry;
use Porta\Help\Layout\CodeLayout;
use Porta\Help\Layout\ImageLayout;
use Porta\Help\Layout\LayoutState;
use Porta\Help\Layout\PageLine;
use Porta\Help\Layout\StylePen;
use Porta\Help\MarkdownRenderer;
use Porta\Markdown\MarkdownParser;
use Porta\Markdown\Node\CodeBlock;
use Porta\Markdown\SourceText;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Graphics\PngRaster;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;

final class MarkdownFenceLayoutTest extends TestCase
{
    use RendersWidget;

    public function testRowsKeepContainersOriginsAndPreviousResultOnFailure(): void
    {
        $registry = new MarkdownFenceRegistry();
        $scheduler = new FakeScheduler();
        $success = null;
        $error = null;
        $registry->add([[
            'plugin' => 'diagrams',
            'languages' => ['mermaid'],
            'render' => static function (string $language, string $source, string $path, int $width, \Closure $ok, \Closure $fail) use (&$success, &$error): \Closure {
                $success = $ok;
                $error = $fail;

                return static function (): void {};
            },
        ]]);
        $session = new FenceSession($registry, new FenceRenderQueue($scheduler), static function (): void {});
        $renderer = new MarkdownRenderer(new Styles(DarkTheme::definition()), fences: $session);
        $markdown = "Before\n\n> ```mermaid\n> first\n> second\n> ```\n\nAfter\n";
        $renderer->prepareFences($markdown, '/doc.md', width: 30);
        $pending = $renderer->render($markdown, width: 40);
        $this->assertStringContainsString('Rendering diagram…', $this->plain($pending->lines));
        $this->assertStringContainsString('second', $this->plain($pending->lines));
        $scheduler->fire();
        $success(FenceResult::rows(["\x1b[31m漢字\x1b[0m", 'other', 'end'], [0, 1, 99], error: 'mmdc warning'));
        $drawn = $renderer->render($markdown, width: 40);
        $plain = $this->rows($drawn->lines);
        $line = (int) \array_find_key($plain, static fn (string $row): bool => \str_contains($row, '漢字'));
        $this->assertStringContainsString('│   漢字', $plain[$line]);
        $this->assertSame([3, 0], $drawn->sourcePointAt($line, col: 5));
        $this->assertSame([4, 0], $drawn->sourcePointAt($line + 1, col: 6));
        $this->assertSame([4, 0], $drawn->sourcePointAt($line + 2, col: 6));
        $this->assertStringContainsString('mmdc warning', \implode("\n", $plain));
        $edited = \str_replace('second', replace: 'invalid', subject: $markdown);
        $renderer->prepareFences($edited, '/doc.md', width: 30);
        $scheduler->fire();
        $error('line 2: bad syntax');
        $failed = $this->plain($renderer->render($edited, width: 40)->lines);
        $this->assertStringContainsString('漢字', $failed);
        $this->assertStringContainsString('bad syntax', $failed);
        $registry->remove('diagrams');
        $source = $this->plain($renderer->render($edited, width: 40)->lines);
        $this->assertStringContainsString('invalid', $source);
        $this->assertStringNotContainsString('漢字', $source);
        $session->clear();
    }

    public function testPngGraphicsFallbackAndRelease(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $layout = new ImageLayout($graphics);
        $bytes = PngFixture::bytes(120, 80);
        $rows = $layout->png($bytes, width: 20);
        $this->assertCount(5, $rows);
        $this->assertSame(15, $rows[0]->width);
        $this->assertCount(1, PixelGraphics::sentImages($terminal));
        $layout->sweep();
        $layout->png($bytes, width: 20);
        $this->assertCount(1, PixelGraphics::sentImages($terminal), 'unchanged bytes reuse their placement');
        $layout->clear();
        $this->assertStringContainsString('a=d', $terminal->getOutput());
        $fallback = new ImageLayout();
        $halfRows = $fallback->png($bytes, width: 20);
        $this->assertCount(5, $halfRows);
        $this->assertStringContainsString('▀', $halfRows[0]->text);
        $this->assertEquals($halfRows, $fallback->png($bytes, width: 20));
        $this->assertNull($fallback->png('bad PNG', width: 20));
        $this->assertNull(new ImageLayout(raster: new PngRaster(available: false))->png($bytes, width: 20));
        for ($width = 1; $width < 67; ++$width) {
            $fallback->png(PngFixture::bytes(800, 16), $width);
        }
        $this->assertCount(24, $fallback->png(PngFixture::bytes(1000, 5000), width: 200));
        $fallback->clear();
    }

    public function testPixelRowsPointAtFenceBodyAndUndisplayableImagesKeepSource(): void
    {
        $registry = new MarkdownFenceRegistry();
        $scheduler = new FakeScheduler();
        $registry->add([[
            'plugin' => 'diagrams',
            'languages' => ['mermaid'],
            'render' => static function (string $language, string $source, string $path, int $width, \Closure $ok): \Closure {
                $ok(FenceResult::png(PngFixture::bytes(16, 16)));

                return static function (): void {};
            },
        ]]);
        $session = new FenceSession($registry, new FenceRenderQueue($scheduler), static function (): void {});
        $markdown = "```mermaid\nA-->B\n```\n";
        $renderer = new MarkdownRenderer(new Styles(DarkTheme::definition()), graphics: PixelGraphics::answered()[0], fences: $session);
        $renderer->prepareFences($markdown, '/doc.md', width: 30);
        $scheduler->fire();
        $doc = $renderer->render($markdown, width: 40);
        $this->assertSame([1, 0], $doc->sourcePointAt(1, col: 2));
        $renderer->clearImages();
        $document = new MarkdownParser()->parse($markdown);
        /** @var CodeBlock $code */
        $code = $document->blocks[0];
        $layout = new CodeLayout(new StylePen(new Styles(DarkTheme::definition())), fences: $session);
        $lines = $layout->lines($code, width: 40, state: new LayoutState(new SourceText($markdown)));
        $plain = $this->plain(\array_map(static fn (PageLine $row): string => $row->text, $lines));
        $this->assertStringContainsString('A-->B', $plain);
        $this->assertStringContainsString('Cannot display diagram PNG', $plain);
        $session->clear();
    }
}
