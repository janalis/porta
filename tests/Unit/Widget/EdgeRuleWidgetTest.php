<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\EdgeRuleWidget;
use Porta\Widget\Render\RowBleed;
use Porta\Widget\Render\Rule;
use Porta\Widget\WorkspaceEdge;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The row across the workspace's edge on a roomy screen — the bar's half, or
 * a rule where the bars have no shade — and no row at all on a compact one.
 */
final class EdgeRuleWidgetTest extends TestCase
{
    use RendersWidget;

    /** Under the menu bar its lower half, over the status bar its upper: each bar half a row taller. */
    public function testARoomyScreenDrawsTheBarsHalfTheWidthOfTheScreen(): void
    {
        $bar = Styles::dark()->of(Role::HeaderBar);
        $top = new EdgeRuleWidget(new ScreenDensity(), WorkspaceEdge::Top, Styles::dark());
        $bottom = new EdgeRuleWidget(new ScreenDensity(), WorkspaceEdge::Bottom, Styles::dark());

        $this->assertSame([RowBleed::under(40, $bar)], $top->render(new RenderContext(40, 5)), 'the menu bar\'s `▀`');
        $this->assertSame([RowBleed::over(40, $bar)], $bottom->render(new RenderContext(40, 5)), 'the status bar\'s `▄`');
        $this->assertSame(\str_repeat(Glyphs::HALF_UPPER, times: 40), AnsiUtils::stripAnsiCodes($top->render(new RenderContext(40, 5))[0]));
        $this->assertTrue($top->holdsBarHalf());
    }

    /** Bars with no shade have no half to give: the edge is a rule again. */
    public function testAThemeWithUnshadedBarsDrawsARule(): void
    {
        $styles = new Styles(MonochromeTheme::definition());
        $rule = new EdgeRuleWidget(new ScreenDensity(), WorkspaceEdge::Top, $styles);

        $this->assertSame([Rule::horizontal(40, $styles)], $rule->render(new RenderContext(40, 5)));
        $this->assertFalse($rule->holdsBarHalf(), 'no half for a pointer to be over');
    }

    public function testACompactScreenGivesTheRowBack(): void
    {
        $density = new ScreenDensity();
        $rule = new EdgeRuleWidget($density, WorkspaceEdge::Bottom);
        $rule->render(new RenderContext(40, 5));
        $density->observe(24);

        $this->assertSame([], $rule->render(new RenderContext(40, 5)));
        $this->assertFalse($rule->holdsBarHalf(), 'no row, no half: a roomy frame\'s does not outlive the flip');
    }

    public function testAFlipRepaintsAndASteadyFrameDoesNot(): void
    {
        $density = new ScreenDensity();
        $rule = new EdgeRuleWidget($density, WorkspaceEdge::Top);
        $rule->beforeRender();
        $rule->render(new RenderContext(40, 5));
        $revision = $rule->getRenderRevision();

        $rule->beforeRender();
        $this->assertSame($revision, $rule->getRenderRevision(), 'the same density: the cached row stands');

        $density->observe(24);
        $rule->beforeRender();
        $this->assertGreaterThan($revision, $rule->getRenderRevision(), 'cached at its own size, the row would outlive the flip');
    }

    public function testADropdownCompositesOverTheEdge(): void
    {
        $rule = new EdgeRuleWidget(new ScreenDensity(), WorkspaceEdge::Top);
        $rule->setMenuOverlay([['row' => 0, 'col' => 2, 'width' => 4, 'segment' => '┏━━┓']]);

        $this->assertSame('▀▀┏━━┓▀▀▀▀', AnsiUtils::stripAnsiCodes($this->painted($rule, new RenderContext(10, 1))[0]));
    }

    /** The edge rows are no chrome root: a theme switch's sweep never reaches them, so they repaint on their own. */
    public function testAThemeSwitchRepaints(): void
    {
        $styles = Styles::dark();
        $rule = new EdgeRuleWidget(new ScreenDensity(), WorkspaceEdge::Top, $styles);
        $rule->beforeRender();
        $rule->render(new RenderContext(40, 5));
        $revision = $rule->getRenderRevision();

        $styles->switchTo(MonochromeTheme::definition());
        $rule->beforeRender();
        $this->assertGreaterThan($revision, $rule->getRenderRevision(), 'cached in the old theme, the bar\'s half would outlive the switch');
    }
}
