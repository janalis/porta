<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help\Fence;

use PHPUnit\Framework\TestCase;
use Porta\Help\Fence\FenceAction;
use Porta\Help\Fence\FenceElement;
use Porta\Help\Fence\FenceResult;
use Porta\Help\Fence\FenceSession;
use Porta\Help\Layout\FenceLinks;
use Porta\Help\Layout\LayoutState;
use Porta\Help\Layout\PageLine;
use Porta\Help\Layout\StylePen;
use Porta\Help\LinkSpan;
use Porta\Help\MarkdownRenderer;
use Porta\Markdown\SourceText;
use Porta\Tests\Support\FenceProvider;
use Porta\Tests\Support\PngFixture;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class FenceLinksTest extends TestCase
{
    private static function element(string $id, string $label, int $origin = 0): FenceElement
    {
        return new FenceElement($id, $label, $origin, [[0.2, 0.0], [0.8, 0.0], [0.8, 1.0], [0.2, 1.0]]);
    }

    public function testPictureRegionsPreserveTopmostOrderAndSearchableWrappedLegends(): void
    {
        $result = FenceResult::png(PngFixture::bytes(8, 8))->interactive([
            self::element('first', "漢字\nlabel\x1b[31m\x07"),
            self::element('second', '', origin: 1),
        ]);
        $state = new LayoutState(new SourceText("first\nsecond\n"));
        $links = new FenceLinks($result, 10, 3, 1, 6, new StylePen(Styles::dark()), $state);
        $rows = $links->image([new PageLine('pixels', 12, 3, [[0, 1, 'old', 7]])]);
        $this->assertSame('fence-element:10:second', $rows[0]->links[0][2]);
        $this->assertSame([4, 10], \array_slice($rows[0]->links[0], offset: 0, length: 2));
        $this->assertSame('old', $rows[0]->links[2][2]);
        $legend = $links->legend();
        $plain = \implode("\n", \array_map(static fn (PageLine $line): string => AnsiUtils::stripAnsiCodes($line->text), $legend));
        $this->assertStringContainsString('漢字', $plain);
        $this->assertStringContainsString('label', $plain);
        $this->assertStringNotContainsString("\x07", $plain);
        $this->assertStringContainsString('""', $plain);
        $this->assertSame(4, \array_last($legend)->origin);
        $this->assertSame(2, $state->nextGroup);
    }

    public function testTerminalLabelsReuseSourceRowsAndMissingLabelsReceiveFallbackLinks(): void
    {
        $result = FenceResult::rows([], [])->interactive([self::element('one', "漢字\nmore", origin: 1), self::element('two', 'Missing')]);
        $state = new LayoutState(new SourceText(''));
        $links = new FenceLinks($result, 20, 3, 1, 10, new StylePen(Styles::dark()), $state);
        $rows = $links->terminal([new PageLine('  漢字 ', 7, 4), new PageLine('漢字', 4, 99)]);
        $this->assertSame([[2, 6, 'fence-element:20:one', 0]], $rows[0]->links);
        $this->assertSame([], $rows[1]->links);
        $legend = $links->legend();
        $this->assertCount(1, $legend);
        $this->assertStringContainsString('Missing', $legend[0]->text);
        $this->assertSame('fence-element:20:two', $legend[0]->links[0][2]);
    }

    public function testControlsWrapAtNarrowWidthsAndAllTargetsRemainActions(): void
    {
        $actions = [];
        foreach (['find' => 'Find node', 'svg' => 'SVG', 'png' => 'PNG'] as $id => $label) {
            $actions[] = new FenceAction($id, $label, static function (): void {});
        }
        foreach ([30, 4, 1] as $width) {
            $state = new LayoutState(new SourceText(''));
            $links = new FenceLinks(FenceResult::rows([], [])->interactive([], $actions), 9, 2, 0, $width, new StylePen(Styles::dark()), $state);
            $rows = $links->controls();
            foreach ($rows as $row) {
                $this->assertLessThanOrEqual($width + 2, $row->width);
                foreach ($row->links as [, , $target, $group]) {
                    $this->assertArrayHasKey($group, $state->actions);
                    $this->assertStringStartsWith('fence-action:9:', $target);
                }
            }
            $this->assertSame(3, $state->nextGroup);
        }
        $empty = new FenceLinks(null, 0, 0, 0, 10, new StylePen(Styles::dark()), new LayoutState(new SourceText('')));
        $this->assertSame([], $empty->image([]));
        $this->assertSame([], $empty->terminal([]));
        $this->assertSame([], $empty->legend());
        $this->assertSame([], $empty->controls());
    }

    public function testSemanticLegendOriginsAreClampedIntoTheirOwnFence(): void
    {
        $result = FenceResult::rows([], [])->interactive([self::element('outside', 'Outside', origin: 99)]);
        $links = new FenceLinks($result, 20, 3, 1, 10, new StylePen(Styles::dark()), new LayoutState(new SourceText('')));
        $this->assertSame(4, $links->legend()[0]->origin);
        $rows = $links->terminal([new PageLine('Outside', 7, 4)]);
        $this->assertCount(1, $rows[0]->links);
    }

    public function testInteractivePngLinksSurviveContainersAndDisappearDuringEditedRetention(): void
    {
        $provider = new FenceProvider();
        $session = new FenceSession($provider->registry, $provider->queue, static function (): void {});
        $renderer = new MarkdownRenderer(Styles::dark(), fences: $session);
        $markdown = "Before\n\n> ```mermaid\n> header\n> left\n> right\n> ```\n";
        $renderer->prepareFences($markdown, '/doc.md', width: 30);
        $provider->drain();
        $action = new FenceAction('export-svg', 'SVG', static function (): void {});
        $provider->requests[0]['success'](FenceResult::png(PngFixture::bytes(120, 80))->interactive([self::element('left', 'Left semantic', origin: 1)], [$action]));
        $drawn = $renderer->render($markdown, width: 40);
        $target = \array_find($drawn->links, static fn ($span): bool => \str_ends_with($span->target, ':left'));
        $this->assertInstanceOf(LinkSpan::class, $target);
        $this->assertTrue($drawn->isAction($target->group));
        $this->assertStringContainsString('Left semantic', AnsiUtils::stripAnsiCodes(\implode("\n", $drawn->lines)));
        $svg = \array_find($drawn->links, static fn ($span): bool => \str_ends_with($span->target, ':export-svg'));
        $this->assertInstanceOf(LinkSpan::class, $svg);
        $edited = \str_replace('right', replace: 'changed', subject: $markdown);
        $renderer->prepareFences($edited, '/doc.md', width: 30);
        $retained = $renderer->render($edited, width: 40);
        $this->assertSame([], $retained->links, 'stale pictures retain pixels but cannot invoke old actions');
        $provider->drain();
        $provider->requests[1]['error']('bad edit');
        $this->assertSame([], $renderer->render($edited, width: 40)->links);
        $session->clear();
    }
}
