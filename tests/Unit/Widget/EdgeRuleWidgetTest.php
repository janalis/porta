<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Widget\EdgeRuleWidget;
use Porta\Widget\Render\Rule;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/** A rule across the workspace's edge on a roomy screen, no row at all on a compact one. */
final class EdgeRuleWidgetTest extends TestCase
{
    use RendersWidget;

    public function testARoomyScreenDrawsOneRuleRowTheWidthOfTheScreen(): void
    {
        $rule = new EdgeRuleWidget(new ScreenDensity(), Styles::dark());

        $this->assertSame([Rule::horizontal(40, Styles::dark())], $rule->render(new RenderContext(40, 5)));
    }

    public function testACompactScreenGivesTheRowBack(): void
    {
        $density = new ScreenDensity();
        $density->observe(24);

        $this->assertSame([], new EdgeRuleWidget($density)->render(new RenderContext(40, 5)));
    }

    public function testAFlipRepaintsAndASteadyFrameDoesNot(): void
    {
        $density = new ScreenDensity();
        $rule = new EdgeRuleWidget($density);
        $rule->beforeRender();
        $rule->render(new RenderContext(40, 5));
        $revision = $rule->getRenderRevision();

        $rule->beforeRender();
        $this->assertSame($revision, $rule->getRenderRevision(), 'the same density: the cached row stands');

        $density->observe(24);
        $rule->beforeRender();
        $this->assertGreaterThan($revision, $rule->getRenderRevision(), 'cached at its own size, the row would outlive the flip');
    }

    public function testADropdownCompositesOverTheRule(): void
    {
        $rule = new EdgeRuleWidget(new ScreenDensity());
        $rule->setMenuOverlay([['row' => 0, 'col' => 2, 'width' => 4, 'segment' => '┏━━┓']]);

        $this->assertSame('──┏━━┓────', AnsiUtils::stripAnsiCodes($this->painted($rule, new RenderContext(10, 1))[0]));
    }
}
