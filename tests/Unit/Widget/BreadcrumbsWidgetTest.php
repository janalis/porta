<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\Render\CrumbBar;
use Porta\Widget\Render\Rule;
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
            ['label' => 'App', 'line' => 1], // pill ' App ' → cols 0..4
            ['label' => 'Ctrl', 'line' => 4], // after the '›' at 5 → pill cols 6..11
        ]);
        $bar->render(new RenderContext(80, 1));

        $this->assertSame(1, $bar->lineAtColumn(2), 'inside the first crumb');
        $this->assertSame(4, $bar->lineAtColumn(8), 'inside the second crumb');
        $this->assertSame(1, $bar->lineAtColumn(0), 'the pill padding is part of the crumb: it is where the tint shows');
        $this->assertSame(4, $bar->lineAtColumn(11), 'so is the trailing padding');
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

        // ' App › Widget › render ' — the 'App' pill spans cols 0-4, 'Widget' 6-13.
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

        $this->assertStringContainsString("\x1b[48;5;238m\x1b[1m \x1b[4mrender", $line, 'the current scope stays lit and bold under the pointer, and underlines: it is a link too');
        $this->assertStringNotContainsString("\x1b[48;5;240m", $line, 'the active shade wins over the hover shade, as on a tab');
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

        // The current+hovered crumb is the active tab's shade — Bright (231)
        // on TabActiveBg (238), bold — with the link underline on its label,
        // applied together: not the shade on one crumb and the bold on another.
        $this->assertStringContainsString("\x1b[38;5;231m\x1b[48;5;238m\x1b[1m \x1b[4mrender", $line);
        $this->assertStringNotContainsString("\x1b[48;5;238m\x1b[1m App", $line, 'a non-current crumb must not be lit');
        $this->assertStringNotContainsString("\x1b[48;5;238m\x1b[1m Widget", $line, 'a non-current crumb must not be lit');
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

        $line = AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(21, 1))[0]);

        $this->assertSame(' … › Widget › render ', $line);
        $this->assertNull($bar->lineAtColumn(2), 'the … is no crumb');
        $this->assertSame(self::trail()[1]['line'], $bar->lineAtColumn(5), 'Widget, where it is drawn now');
    }

    /** The `…` names what it hides; a crumb drawn whole needs no card. */
    public function testTheLeadSaysWhichAncestorsItHides(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(self::trail(...)); // App › Widget › render
        $bar->render(new RenderContext(18, 1)); // ' … › render '

        $lead = $bar->tooltipAt(0, 1);
        $this->assertSame(['crumbs:lead', 'App › Widget', 0, 3], [$lead?->key, $lead?->text, $lead?->colStart, $lead?->colEnd], 'the whole ` … ` pill');
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

        $this->assertSame(' App › Widget › render ', AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(23, 1))[0]));
        $this->assertSame(' … › Widget › render  ', AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(22, 1))[0]));
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
        $this->assertMatchesRegularExpression('/^ … › hand.*….*uest $/u', $line);
    }

    public function testHoveringExactlyOnePastACrumbsWidthDoesNotHoverIt(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [['label' => 'App', 'line' => 0], ['label' => 'Widget', 'line' => 4]]);
        $bar->render(new RenderContext(80, 1));

        // The 'Widget' pill spans columns [6, 14) — col 14 is one past its last column.
        $bar->hoverAt(14);
        $line = $bar->render(new RenderContext(80, 1))[0];
        $this->assertStringNotContainsString("\x1b[48;5;240m", $line, 'one column past the crumb is not on the crumb');
        $this->assertNull($bar->lineAtColumn(14), 'crumbIndexAt must not treat the boundary column as inside the crumb');
    }

    public function testSeparatorAdvancesTheColumnByItsFullThreeCharWidth(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [['label' => 'AB', 'line' => 0], ['label' => 'CD', 'line' => 1]]);
        $bar->render(new RenderContext(80, 1));

        // The ' AB ' pill occupies cols [0,4) and the '›' col 4, so the ' CD '
        // pill must start at col 5 — its label at 6, three columns after AB's.
        $this->assertNull($bar->lineAtColumn(4), 'the separator is not a crumb');
        $this->assertSame(1, $bar->lineAtColumn(5), 'the second crumb starts right after the separator');
    }

    public function testACrumbIsAsWideAsItIsDrawn(): void
    {
        // 'Cafe\u{0301}' is drawn four columns wide; counted by code point it
        // was five, and every crumb after it clicked one column late.
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => [['label' => "Cafe\u{0301}", 'line' => 0], ['label' => 'Menu', 'line' => 1]]);
        $bar->render(new RenderContext(80, 1));

        $this->assertNull($bar->lineAtColumn(6), 'the separator after a four-column crumb');
        $this->assertSame(1, $bar->lineAtColumn(7), 'the next crumb starts where it is drawn');
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
        $bar->hoverAt(2); // over the first crumb's pill ' App ' (cols 0-4)

        // A relabel that keeps the same shape: the pointer still sits on the
        // first crumb, so its tint must stay without a fresh motion event.
        $crumbs[2]['label'] = 'renamed';
        $bar->beforeRender();
        $line = $bar->render(new RenderContext(80, 1))[0];

        $this->assertStringContainsString("\x1b[48;5;240m", $line, 'the first crumb stays tinted through a downstream relabel');
    }

    /** @return list<array{label: string, line: ?int, path?: ?string}> */
    private static function pathTrail(): array
    {
        return [
            ['label' => 'src', 'line' => null, 'path' => '/p/src'],
            ['label' => 'Svc.php', 'line' => null, 'path' => '/p/src/Svc.php'],
            ['label' => 'Svc', 'line' => 3, 'path' => null],
        ];
    }

    public function testAPathCrumbRevealsItsPathAndAScopeCrumbJumps(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(self::pathTrail(...));
        $bar->render(new RenderContext(80, 1)); // ' src › Svc.php › Svc ' — pills [0,5) [6,15) [16,21)

        $this->assertSame('/p/src', $bar->pathAtColumn(2));
        $this->assertNull($bar->lineAtColumn(2), 'a folder has no line to jump to');
        $this->assertSame('/p/src/Svc.php', $bar->pathAtColumn(8));
        $this->assertNull($bar->pathAtColumn(18), 'a scope reveals nothing');
        $this->assertSame(3, $bar->lineAtColumn(18));
        $this->assertNull($bar->pathAtColumn(5), 'the separator');
    }

    public function testTheFileCrumbKeepsABrightForegroundAtRest(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(self::pathTrail(...));

        $line = $bar->render(new RenderContext(80, 1))[0];

        // Emphasis (255) on the Toolbar strip (235): the seam between where
        // the file is and where in it the caret is.
        $this->assertStringContainsString("\x1b[38;5;255m\x1b[48;5;235m Svc.php ", $line);
        $this->assertStringNotContainsString("\x1b[38;5;255m src ", $line, 'a folder is not the file');
    }

    public function testWithNoScopeTheFileIsTheCurrentCrumb(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(static fn (): array => \array_slice(self::pathTrail(), offset: 0, length: 2));

        $line = $bar->render(new RenderContext(80, 1))[0];

        $this->assertStringContainsString("\x1b[38;5;231m\x1b[48;5;238m\x1b[1m Svc.php ", $line, 'lit like the active tab');
    }

    /**
     * A roomy screen gives the bar its lower half in the row under it, so it
     * reads half a row taller; a compact one gives the row back. The row has
     * no card of its own: only row 0 answers the pointer.
     */
    public function testARoomyScreenDrawsTheBarsHalfUnderIt(): void
    {
        $density = new ScreenDensity();
        $bar = new BreadcrumbsWidget(null, new Translator('en'), null, $density);
        $bar->setProvider(self::trail(...));

        $rows = $bar->render(new RenderContext(40, 2));
        $this->assertCount(2, $rows);
        $this->assertSame(Rule::under(40, Styles::dark(), CrumbBar::SHADE), $rows[1]);
        $this->assertSame(\str_repeat('▀', times: 40), AnsiUtils::stripAnsiCodes($rows[1]), 'the bar\'s half, not a rule');
        $this->assertNull($bar->tooltipAt(1, 1), 'the half has no card');

        $density->observe(24);
        $bar->beforeRender();
        $rev = $bar->getRenderRevision();
        $this->assertCount(1, $bar->render(new RenderContext(40, 1)), 'compact: no rule');
        $density->observe(40);
        $bar->beforeRender();
        $this->assertGreaterThan($rev, $bar->getRenderRevision(), 'a density flip repaints the bar');
    }

    public function testABareBarDrawsNoRule(): void
    {
        $bar = new BreadcrumbsWidget();
        $bar->setProvider(self::trail(...));

        $this->assertCount(1, $bar->render(new RenderContext(40, 2)), 'no screen to ask, no rule');
    }
}
