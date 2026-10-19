<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Help\Fence\FenceRenderQueue;
use Porta\Help\Fence\FenceResult;
use Porta\Help\Fence\MarkdownFenceRegistry;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\RendersWidget;
use Porta\Widget\MarkdownPreviewWidget;
use Symfony\Component\Tui\Render\RenderContext;

final class MarkdownFencePreviewTest extends TestCase
{
    use RendersWidget;

    public function testPendingAndUndisplayableImageMessagesAreTranslated(): void
    {
        $scheduler = new FakeScheduler();
        $registry = new MarkdownFenceRegistry();
        $registry->add([[
            'plugin' => 'diagrams',
            'languages' => ['mermaid'],
            'render' => static function (string $language, string $source, string $path, int $width, \Closure $success): \Closure {
                // A valid header with an incomplete bitmap cannot be decoded by GD.
                $success(FenceResult::png(\substr(PngFixture::bytes(16, 16), offset: 0, length: 24)));

                return static function (): void {};
            },
        ]]);
        $translator = new Translator('fr');
        $pane = new MarkdownPreviewWidget(translator: $translator, fences: $registry, fenceQueue: new FenceRenderQueue($scheduler), scheduler: $scheduler);
        $pane->show('/doc.md', "```mermaid\nsource\n```\n");
        $context = new RenderContext(70, 15);
        $pane->render($context);
        $scheduler->fire();
        $this->assertStringContainsString($translator->trans('Rendering diagram…'), $this->plain($pane->render($context)));
        $scheduler->fire();
        $this->assertStringContainsString($translator->trans('Cannot display diagram PNG'), $this->plain($pane->render($context)));
        $this->assertStringContainsString('source', $this->plain($pane->render($context)));
        $pane->hide();
    }

    public function testDeferredPreparationCompletionResizeHideAndDisable(): void
    {
        $scheduler = new FakeScheduler();
        $registry = new MarkdownFenceRegistry();
        $success = null;
        $cancelled = 0;
        $started = 0;
        $registry->add([[
            'plugin' => 'diagrams',
            'languages' => ['mermaid'],
            'render' => static function (string $language, string $source, string $path, int $width, \Closure $ok) use (&$success, &$started, &$cancelled): \Closure {
                ++$started;
                $success = $ok;

                return static function () use (&$cancelled): void {
                    ++$cancelled;
                };
            },
        ]]);
        $pane = new MarkdownPreviewWidget(fences: $registry, fenceQueue: new FenceRenderQueue($scheduler), scheduler: $scheduler);
        $source = "Before\n\n```mermaid\nsource\n```\n\nAfter";
        $pane->show('/doc.md', $source);
        $context = new RenderContext(50, 15);
        $this->assertStringContainsString('source', $this->plain($pane->render($context)));
        $this->assertSame(0, $started, 'painting does not start the renderer');
        $scheduler->fire();
        $this->assertStringContainsString('Rendering diagram…', $this->plain($pane->render($context)));
        $scheduler->fire();
        $this->assertSame(1, $started);
        $success(FenceResult::rows(['diagram'], [0]));
        $this->assertStringContainsString('diagram', $this->plain($pane->render($context)));
        $this->assertStringNotContainsString('source', $this->plain($pane->render($context)));
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $pane->render(new RenderContext(60, 15));
        $scheduler->fire();
        $scheduler->fire();
        $this->assertSame(2, $started);
        $stale = $success;
        $pane->hide();
        $this->assertSame(1, $cancelled);
        $stale(FenceResult::rows(['stale'], [0]));
        $pane->show('/doc.md', $source);
        $scheduler->fire();
        $scheduler->fire();
        $this->assertSame(3, $started, 'the same text restarts after hiding');
        $registry->refresh('unrelated');
        $this->assertSame(1, $cancelled);
        $registry->refresh('diagrams');
        $this->assertSame(2, $cancelled);
        $registry->remove('diagrams');
        $this->assertStringContainsString('source', $this->plain($pane->render($context)));
        $pane->forget('/other.md');
        $this->assertNotNull($pane->path());
        $pane->forget('/doc.md');
        $this->assertNull($pane->path());
        $pane->hide();
        $this->assertSame([], $scheduler->scheduled);
    }
}
