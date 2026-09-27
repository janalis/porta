<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RendersWidget;
use Porta\Widget\BreadcrumbsWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class BreadcrumbsWidgetTest extends TestCase
{
    use RendersWidget;

    public function testEmptyTrailRendersNoRow(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => []);
        $bar->beforeRender();

        $this->assertSame([], $bar->render(new RenderContext(80, 1)), 'no scope → no bar');
        $this->assertNull($bar->lineAtColumn(5), 'nothing to hit-test');
    }

    public function testHidingTheBarRendersNoRowEvenWithATrail(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [['label' => 'App', 'line' => 1]]);

        $this->assertTrue($bar->isVisible(), 'the bar shows by default');
        $bar->hide();
        $this->assertFalse($bar->isVisible());
        $this->assertSame([], $bar->render(new RenderContext(80, 1)), 'the Interface setting hides the bar');
        $this->assertNull($bar->lineAtColumn(2), 'a hidden bar hit-tests nothing');

        $bar->show();
        $this->assertTrue($bar->isVisible());
        $this->assertNotSame([], $bar->render(new RenderContext(80, 1)), 'showing brings the trail back');
    }

    public function testAHiddenBarNeverRunsItsProvider(): void
    {
        $calls = 0;
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static function () use (&$calls): array {
            ++$calls;

            return [['label' => 'App', 'line' => 1]];
        });

        $bar->hide();
        $bar->beforeRender();
        $this->assertSame(0, $calls, 'the provider tokenizes the file — a hidden bar must not pay that per frame');

        $bar->show();
        $bar->beforeRender();
        $this->assertSame(1, $calls, 'showing the bar puts the provider back on the frame path');
    }

    public function testRendersSegmentsJoinedAndExactWidth(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [
            ['label' => 'App', 'line' => 1],
            ['label' => 'UserController', 'line' => 4],
            ['label' => 'index', 'line' => 6],
        ]);

        $rows = $bar->render(new RenderContext(80, 1));
        $this->assertCount(1, $rows);
        $this->assertSame(80, AnsiUtils::visibleWidth($rows[0]), 'padded to the full width');

        $plain = AnsiUtils::stripAnsiCodes($rows[0]);
        $this->assertStringContainsString('App › UserController › index', $plain);
    }

    public function testLineAtColumnMapsToJumpLines(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [
            ['label' => 'App', 'line' => 1], // ' App' → cols 1..3
            ['label' => 'Ctrl', 'line' => 4], // after ' › ' → cols 7..10
        ]);
        $bar->render(new RenderContext(80, 1));

        $this->assertSame(1, $bar->lineAtColumn(2), 'inside the first crumb');
        $this->assertSame(4, $bar->lineAtColumn(8), 'inside the second crumb');
        $this->assertNull($bar->lineAtColumn(0), 'the leading space is not a crumb');
        $this->assertNull($bar->lineAtColumn(5), 'the separator gap is not a crumb');
        $this->assertNull($bar->lineAtColumn(70), 'past the last crumb');
    }

    public function testBeforeRenderInvalidatesOnChange(): void
    {
        $bar = new BreadcrumbsWidget();
        $crumbs = [['label' => 'A', 'line' => 0]];
        $bar->setProvider(static function () use (&$crumbs): array {
            return $crumbs;
        });

        $bar->beforeRender();
        $rev = $bar->getRenderRevision();
        $bar->beforeRender(); // unchanged → no new revision
        $this->assertSame($rev, $bar->getRenderRevision());

        $crumbs = [['label' => 'A', 'line' => 0], ['label' => 'B', 'line' => 2]];
        $bar->beforeRender(); // changed → invalidates
        $this->assertGreaterThan($rev, $bar->getRenderRevision());
    }

    public function testMenuOverlayCompositesOverTheBar(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [['label' => 'App', 'line' => 1]]);
        $bar->setMenuOverlay([['row' => 0, 'col' => 2, 'width' => 7, 'segment' => "\x1b[7m Paste \x1b[0m"]]);

        $row = $this->painted($bar, new RenderContext(40, 1))[0];
        $this->assertStringContainsString('Paste', AnsiUtils::stripAnsiCodes($row), 'a dropdown span covers the breadcrumb row');
    }

    public function testReDeliveringIdenticalOverlaySpansDoesNotInvalidate(): void
    {
        // The presenters re-deliver on every frame hook; only a CHANGE may
        // invalidate, or any visible overlay self-sustains a repaint loop.
        $bar = new BreadcrumbsWidget();
        $span = static fn (): array => [['row' => 0, 'col' => 2, 'width' => 3, 'segment' => \implode('', ['a', 'b', 'c'])]];
        $bar->setMenuOverlay($span());
        $bar->setModalOverlay($span());
        $bar->setToastOverlay($span());
        $rev = $bar->getRenderRevision();

        // Fresh arrays, equal content — exactly what a frame hook re-delivers.
        $bar->setMenuOverlay($span());
        $bar->setModalOverlay($span());
        $bar->setToastOverlay($span());
        $this->assertSame($rev, $bar->getRenderRevision(), 'identical spans must not bump the revision');

        $bar->setToastOverlay([]);
        $this->assertGreaterThan($rev, $bar->getRenderRevision(), 'a real change still invalidates');
    }

    public function testMenuOverlayIgnoredOnEmptyTrail(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => []);
        $bar->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 5, 'segment' => 'XXXXX']]);

        $this->assertSame([], $bar->render(new RenderContext(40, 1)), 'no bar → nothing to composite onto');
    }

    public function testProviderlessRenderIsEmpty(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->beforeRender(); // no provider set → no-op
        $this->assertSame([], $bar->render(new RenderContext(40, 1)));
    }

    /** @return list<array{label: string, line: int}> */
    private static function trail(): array
    {
        return [['label' => 'App', 'line' => 0], ['label' => 'Widget', 'line' => 4], ['label' => 'render', 'line' => 9]];
    }

    public function testHoverTintsTheCrumbUnderThePointer(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(self::trail(...));
        $bar->beforeRender();
        $bar->render(new RenderContext(80, 1));

        // ' App › Widget › render' — 'App' spans cols 1-3, 'Widget' 7-12.
        $this->assertTrue($bar->hoverAt(7), 'the pointer landed on a crumb');
        $this->assertFalse($bar->hoverAt(8), 'still the same crumb → no change');
        $this->assertTrue($bar->hoverAt(5), 'the separator is not a crumb: the tint clears');
        $this->assertFalse($bar->hoverAt(5), 'still no crumb → no change');
        $this->assertTrue($bar->hoverAt(1), 'back onto a crumb');
        $this->assertTrue($bar->clearHover(), 'the pointer left the bar');
        $this->assertFalse($bar->clearHover(), 'nothing left to clear');
    }

    public function testTheHoveredCrumbCarriesTheBarHoverShade(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(self::trail(...));
        $bar->beforeRender();
        $bar->render(new RenderContext(80, 1)); // the hover maps against the last render's layout

        $bar->hoverAt(7); // 'Widget'
        $line = $bar->render(new RenderContext(80, 1))[0];

        // Bar chrome hovers on BarHoverBg (240) — not a list row's tint (236).
        $this->assertStringContainsString("\x1b[48;5;240m", $line, 'the hovered crumb takes the bar-chrome hover shade');
        $this->assertStringNotContainsString("\x1b[48;5;236m", $line, 'not the list-row tint');
        $this->assertSame(80, AnsiUtils::visibleWidth($line), 'the tint does not change the bar width');
    }

    public function testHoveringTheCurrentScopeKeepsItBold(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(self::trail(...));
        $bar->beforeRender();
        $bar->render(new RenderContext(80, 1));

        $bar->hoverAt(16); // 'render' — the current scope
        $line = $bar->render(new RenderContext(80, 1))[0];

        $this->assertStringContainsString("\x1b[48;5;240m", $line, 'hover shade');
        $this->assertStringContainsString("\x1b[1m", $line, 'the current scope stays bold under the pointer');
    }

    public function testAChangedTrailDropsAStaleTint(): void
    {
        $bar = new BreadcrumbsWidget();
        $crumbs = self::trail();
        $bar->setProvider(static function () use (&$crumbs): array {
            return $crumbs;
        });
        $bar->beforeRender();
        $bar->render(new RenderContext(80, 1));
        $bar->hoverAt(7);

        // The caret moved: the crumbs change under a pointer that has not.
        $crumbs = [['label' => 'Other', 'line' => 2]];
        $bar->beforeRender();
        $line = $bar->render(new RenderContext(80, 1))[0];

        $this->assertStringNotContainsString("\x1b[48;5;240m", $line, 'the hovered column maps to no crumb in the shorter trail, so nothing is tinted');
    }

    public function testShowAndHideInvalidateOnlyWhenTheStateActuallyChanges(): void
    {
        $bar = new BreadcrumbsWidget();
        $rev = $bar->getRenderRevision();

        $bar->show(); // already visible → no-op
        $this->assertSame($rev, $bar->getRenderRevision());

        $bar->hide();
        $this->assertGreaterThan($rev, $bar->getRenderRevision(), 'hide() invalidates');
        $rev = $bar->getRenderRevision();

        $bar->hide(); // already hidden → no-op
        $this->assertSame($rev, $bar->getRenderRevision());

        $bar->show();
        $this->assertGreaterThan($rev, $bar->getRenderRevision(), 'show() invalidates');
    }

    public function testOnlyTheCurrentAndHoveredCrumbCarriesBothTheBoldAndHoverShadeTogether(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(self::trail(...)); // App, Widget, render (last = current)
        $bar->render(new RenderContext(80, 1));
        $bar->hoverAt(16); // 'render': the last crumb, i.e. current

        $line = $bar->render(new RenderContext(80, 1))[0];

        // The current+hovered crumb gets Emphasis fg (255) + BarHoverBg (240) + bold
        // + the link underline, in that fixed order, applied together — not the
        // shade on one crumb and the bold on another.
        $this->assertStringContainsString("\x1b[38;5;255m\x1b[48;5;240m\x1b[1m\x1b[4mrender", $line);
        $this->assertStringNotContainsString("\x1b[48;5;240m\x1b[1mApp", $line, 'a non-current, non-hovered crumb must not pick up the hover+bold combo');
        $this->assertStringNotContainsString("\x1b[48;5;240m\x1b[1mWidget", $line, 'a non-current, non-hovered crumb must not pick up the hover+bold combo');
    }

    /**
     * Too narrow for the trail, the bar drops ancestors from the ROOT end
     * behind one `… › ` and keeps the scope the caret is in — it used to cut
     * the right end, which is the one crumb that says where you are. Only
     * the crumbs drawn can be clicked.
     */
    public function testANarrowBarKeepsTheCurrentScopeAndCutsTheRoot(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(self::trail(...)); // App › Widget › render

        $line = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(20, 1))[0]);

        $this->assertSame(' … › Widget › render', $line);
        $this->assertNull($bar->lineAtColumn(2), 'the … is no crumb');
        $this->assertSame(self::trail()[1]['line'], $bar->lineAtColumn(5), 'Widget, where it is drawn now');
    }

    /** The `…` names what it hides; a crumb drawn whole needs no card. */
    public function testTheLeadSaysWhichAncestorsItHides(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(self::trail(...)); // App › Widget › render
        $bar->render(new RenderContext(18, 1)); // ' … › render'

        $lead = $bar->tooltipAt(0, 1);
        $this->assertSame(['crumbs:lead', 'App › Widget', 1, 2], [$lead?->key, $lead?->text, $lead?->colStart, $lead?->colEnd]);
        $this->assertNull($bar->tooltipAt(0, 6), 'render is drawn whole');
        $this->assertNull($bar->tooltipAt(1, 1), 'one row');

        $bar->render(new RenderContext(40, 1));
        $this->assertNull($bar->tooltipAt(0, 1), 'nothing hidden, nothing to say');
    }

    /** Wider by one column, the root fits and the lead goes: never `… ›` in front of nothing hidden. */
    public function testTheLeadAppearsOnlyWhenAnAncestorIsHidden(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(self::trail(...));

        $this->assertSame(' App › Widget › render', AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(22, 1))[0]));
        $this->assertSame(' … › Widget › render ', AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(21, 1))[0]));
        // Widget alone would fit at 18; with the lead App's absence needs, it does not.
        $this->assertSame(' … › render       ', AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(18, 1))[0]));
    }

    public function testAnElidedCurrentScopeSaysItsWholeName(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [['label' => 'handleIncomingRequest', 'line' => 9]]);
        $bar->render(new RenderContext(12, 1));

        $this->assertSame('handleIncomingRequest', $bar->tooltipAt(0, 3)?->text);
    }

    /** Alone and still too wide, the current scope keeps both of its ends. */
    public function testATooWideCurrentScopeIsElidedInTheMiddle(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [['label' => 'App', 'line' => 0], ['label' => 'handleIncomingRequest', 'line' => 9]]);

        $line = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(16, 1))[0]);

        $this->assertSame(16, \mb_strwidth($line));
        $this->assertMatchesRegularExpression('/^ … › handl.*….*uest$/u', $line);
    }

    public function testHoveringExactlyOnePastACrumbsWidthDoesNotHoverIt(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [['label' => 'App', 'line' => 0], ['label' => 'Widget', 'line' => 4]]);
        $bar->render(new RenderContext(80, 1));

        // 'Widget' spans columns [7, 13) — col 13 is one past its last column.
        $bar->hoverAt(13);
        $line = $bar->render(new RenderContext(80, 1))[0];
        $this->assertStringNotContainsString("\x1b[48;5;240m", $line, 'one column past the crumb is not on the crumb');
        $this->assertNull($bar->lineAtColumn(13), 'crumbIndexAt must not treat the boundary column as inside the crumb');
    }

    public function testSeparatorAdvancesTheColumnByItsFullThreeCharWidth(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [['label' => 'AB', 'line' => 0], ['label' => 'CD', 'line' => 1]]);
        $bar->render(new RenderContext(80, 1));

        // 'AB' occupies cols [1,3); the separator ' › ' is exactly 3 cols wide,
        // so 'CD' must start at col 6, not col 5.
        $this->assertNull($bar->lineAtColumn(5), 'the last column of the separator is not a crumb');
        $this->assertSame(1, $bar->lineAtColumn(6), 'the second crumb starts right after the full-width separator');
    }

    public function testARelabelUnderAStillPointerKeepsTheTint(): void
    {
        $bar = new BreadcrumbsWidget();
        $crumbs = self::trail();
        $bar->setProvider(static function () use (&$crumbs): array {
            return $crumbs;
        });
        $bar->beforeRender();
        $bar->render(new RenderContext(80, 1));
        $bar->hoverAt(2); // over the first crumb 'App' (cols 1-3)

        // A relabel that keeps the same shape: the pointer still sits on the
        // first crumb, so its tint must stay without a fresh motion event.
        $crumbs[2]['label'] = 'renamed';
        $bar->beforeRender();
        $line = $bar->render(new RenderContext(80, 1))[0];

        $this->assertStringContainsString("\x1b[48;5;240m", $line, 'the first crumb stays tinted through a downstream relabel');
    }
}
