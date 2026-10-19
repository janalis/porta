<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Help\Fence\FenceAction;
use Porta\Help\Fence\FenceContext;
use Porta\Help\Fence\FenceElement;
use Porta\Help\Fence\FenceResult;
use Porta\Help\LinkSpan;
use Porta\Help\RenderedDoc;
use Porta\Tests\Support\FenceProvider;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingHelpViewerHandler;
use Porta\Tests\Support\TestTui;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\MarkdownPageWidget;
use Porta\Widget\MarkdownPreviewWidget;
use Symfony\Component\Tui\Render\RenderContext;

final class MarkdownFenceInteractionTest extends TestCase
{
    private static function semanticResult(array $actions): FenceResult
    {
        return FenceResult::png(PngFixture::bytes(120, 80))->interactive([
            new FenceElement('left', 'Left semantic', 1, [[0.0, 0.0], [0.45, 0.0], [0.45, 1.0], [0.0, 1.0]]),
            new FenceElement('right', 'Right semantic', 2, [[0.55, 0.0], [1.0, 0.0], [1.0, 1.0], [0.55, 1.0]]),
        ], $actions);
    }

    private function click(MarkdownPageWidget $pane, string $suffix): LinkSpan
    {
        /** @var RenderedDoc $doc */
        $doc = Probe::get($pane, 'doc');
        $span = \array_find($doc->links, static fn (LinkSpan $span): bool => \str_ends_with($span->target, $suffix));
        $this->assertInstanceOf(LinkSpan::class, $span);
        $this->assertTrue($pane->clickAt($span->line + 2 - Probe::get($pane, 'scrollTop'), $span->col + Probe::get($pane, 'marginCols')));

        return $span;
    }

    public function testAnAsynchronousFenceCompletionRequestsItsOwnFrame(): void
    {
        $provider = new FenceProvider();
        $pane = new MarkdownPreviewWidget(fences: $provider->registry, fenceQueue: $provider->queue, scheduler: $provider->scheduler);
        $tui = TestTui::create();
        $tui->add($pane);
        $pane->show('/doc.md', "```mermaid\nflowchart LR\nA-->B\n```");
        $context = new RenderContext(80, 40);
        $pane->render($context);
        $provider->drain();
        Probe::set($tui, 'renderRequested', false);
        $provider->requests[0]['success'](self::semanticResult([]));
        $this->assertTrue(Probe::get($tui, 'renderRequested'), 'completion must paint without another key, edit or timer');
        $pane->render($context);
        $this->assertStringContainsString('Left semantic', \implode("\n", $pane->findLines));
        $pane->show('/doc.md', "```mermaid\nflowchart LR\nA-->C\n```");
        $pane->render($context);
        $provider->drain();
        Probe::set($tui, 'renderRequested', false);
        $provider->requests[1]['error']('Broken diagram');
        $this->assertTrue(Probe::get($tui, 'renderRequested'), 'an asynchronous failure also needs its own frame');
        $pane->render($context);
        $this->assertStringContainsString('Broken diagram', \implode("\n", $pane->findLines));
        $pane->hide();
    }

    public function testPixelsPickerKeyboardAndPageSearchRevealTheCurrentFenceSource(): void
    {
        $provider = new FenceProvider();
        $pane = new MarkdownPreviewWidget(fences: $provider->registry, fenceQueue: $provider->queue, scheduler: $provider->scheduler);
        $handler = new RecordingHelpViewerHandler();
        $pane->setHandler($handler);
        $source = "Before\n\n> ```mermaid\n> header\n> left\n> right\n> ```\n";
        $pane->show('/doc.md', $source);
        $context = new RenderContext(80, 40);
        $pane->render($context);
        $provider->drain();
        $contexts = [];
        $action = new FenceAction('find-node', 'Find node', static function (FenceContext $value) use (&$contexts): void {
            $contexts[] = $value;
        });
        $provider->requests[0]['success'](self::semanticResult([$action]));
        $pane->render($context);
        $this->assertStringContainsString('Left semantic', \implode("\n", $pane->findLines));
        $this->assertStringContainsString('Right semantic', \implode("\n", $pane->findLines));
        $this->click($pane, ':left');
        $this->assertSame('reveal:4:0', \array_last($handler->events));
        $pane->render($context);
        $this->click($pane, ':find-node');
        $this->assertCount(1, $contexts);
        $this->assertSame(1, $contexts[0]->number);
        $contexts[0]->select('right');
        $this->assertSame('reveal:5:0', \array_last($handler->events));
        /** @var RenderedDoc $doc */
        $doc = Probe::get($pane, 'doc');
        $right = \array_find($doc->links, static fn (LinkSpan $span): bool => \str_ends_with($span->target, ':right'));
        $this->assertSame($right->group, Probe::get($pane, 'currentLink'));
        $pane->render($context);
        $pane->handleInput("\t");
        $pane->handleInput("\r");
        $this->assertCount(2, $contexts, 'Tab and Enter activate the generated provider control');
        $pane->handleInput("\x06");
        $this->assertSame('find', \array_last($handler->events));
        $pane->show('/doc.md', $source);
        $contexts[0]->select('left');
        $this->assertSame('reveal:4:0', \array_last($handler->events), 'same-source refreshes can select before the next paint');
        $shifted = \str_replace("Before\n\n", replace: "B\nf\no\ne\n", subject: $source);
        $pane->show('/doc.md', $shifted);
        $contexts[0]->select('left');
        $this->assertSame('reveal:6:0', \array_last($handler->events), 'equal byte offsets can have different current source lines');
        $before = $handler->events;
        $pane->show('/doc.md', \str_replace('right', replace: 'edited', subject: $shifted));
        $contexts[0]->select('left');
        $this->assertSame($before, $handler->events, 'a picker opened before the edit cannot select its stale result');
        $pane->hide();
        $contexts[1]->select('right');
        $this->assertSame($before, $handler->events);
        $this->assertSame([], $provider->scheduler->scheduled);
    }

    public function testAnAuthoredLinkCannotImpersonateAGeneratedExportAction(): void
    {
        $provider = new FenceProvider();
        $pane = new MarkdownPreviewWidget(fences: $provider->registry, fenceQueue: $provider->queue, scheduler: $provider->scheduler);
        $pane->setHandler(new RecordingHelpViewerHandler());
        $prefix = "```mermaid\nheader\nleft\nright\n```\n\n";
        $target = 'fence-action:'.\strpos($prefix, needle: 'header').':export-svg';
        $pane->show('/doc.md', $prefix.'[spoof]('.$target.')');
        $context = new RenderContext(80, 40);
        $pane->render($context);
        $provider->drain();
        $exports = 0;
        $action = new FenceAction('export-svg', 'SVG', static function () use (&$exports): void {
            ++$exports;
        });
        $provider->requests[0]['success'](self::semanticResult([$action]));
        $pane->render($context);
        /** @var RenderedDoc $doc */
        $doc = Probe::get($pane, 'doc');
        $this->assertInstanceOf(LinkSpan::class, \array_find($doc->links, static fn (LinkSpan $span): bool => $target === $span->target && $doc->isAction($span->group)));
        $spoof = \array_find($doc->links, static fn (LinkSpan $span): bool => $target === $span->target && !$doc->isAction($span->group));
        $this->assertInstanceOf(LinkSpan::class, $spoof);
        $pane->clickAt($spoof->line + 2, $spoof->col + 1);
        $this->assertSame(0, $exports);
        $this->click($pane, ':export-svg');
        $this->assertSame(1, $exports);
        $pane->hide();
    }

    public function testHelpUsesTheSameControlsWithoutAnEditorSourceSideEffect(): void
    {
        $provider = new FenceProvider();
        $pane = new HelpViewerWidget(fences: $provider->registry, fenceQueue: $provider->queue, scheduler: $provider->scheduler);
        $handler = new RecordingHelpViewerHandler();
        $pane->setHandler($handler);
        $pane->show('/doc.md', "```mermaid\nheader\nleft\nright\n```\n");
        $context = new RenderContext(70, 30);
        $pane->render($context);
        $provider->drain();
        $provider->requests[0]['success'](self::semanticResult([]));
        $pane->render($context);
        $span = $this->click($pane, ':right');
        $this->assertSame($span->group, Probe::get($pane, 'currentLink'));
        $this->assertSame([], $handler->events);
        $pane->hide();
    }
}
