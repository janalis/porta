<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RendersExactWidth;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\Rule;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The one separating line: exact width at every size, a legend set into it
 * only while a stroke still follows, tees that are never cut, and the rule's
 * own tint wherever it runs.
 */
final class RuleTest extends TestCase
{
    use RendersExactWidth;

    public function testABareRuleIsTheRunAlone(): void
    {
        $this->assertSame('──────', AnsiUtils::stripAnsiCodes(Rule::horizontal(6, Styles::dark())));
    }

    public function testTheLegendSitsAfterTheFirstStroke(): void
    {
        $this->assertSame('─ Editor ─────', AnsiUtils::stripAnsiCodes(Rule::horizontal(14, Styles::dark(), 'Editor')));
    }

    public function testTheTeesJoinTheFrameOnEitherSide(): void
    {
        $this->assertSame('├──────┤', AnsiUtils::stripAnsiCodes(Rule::horizontal(8, Styles::dark(), tees: Role::TabAccent)));
        $this->assertSame('├─ Files ──┤', AnsiUtils::stripAnsiCodes(Rule::horizontal(12, Styles::dark(), 'Files', Role::TabAccent)));
    }

    public function testTheLegendYieldsBeforeTheTeesDo(): void
    {
        // Room for the tees and a run, not for `─ F… ─`: the frame stays shut.
        $this->assertSame('├───┤', AnsiUtils::stripAnsiCodes(Rule::horizontal(5, Styles::dark(), 'Files', Role::TabAccent)));
        $this->assertSame('├─ F… ─┤', AnsiUtils::stripAnsiCodes(Rule::horizontal(8, Styles::dark(), 'Files', Role::TabAccent)));
    }

    public function testALegendNeverEndsTheRule(): void
    {
        // Four columns hold `─ X ` with nothing after: a legend needs a stroke
        // on both sides to read as set INTO a line.
        $this->assertSame('────', AnsiUtils::stripAnsiCodes(Rule::horizontal(4, Styles::dark(), 'X')));
        $this->assertSame('─ X ─', AnsiUtils::stripAnsiCodes(Rule::horizontal(5, Styles::dark(), 'X')));
    }

    public function testEveryWidthIsExactEvenForAWideLegend(): void
    {
        foreach (['', 'Editor', '編集者の設定'] as $legend) {
            foreach ([null, Role::TabAccent] as $tees) {
                for ($width = 0; $width <= 16; ++$width) {
                    self::assertExactWidth([Rule::horizontal($width, Styles::dark(), $legend, $tees)], $width, "'{$legend}' at {$width}");
                }
            }
        }
        self::assertExactWidth([Rule::horizontal(-3, Styles::dark(), 'Editor')], 0, 'a negative width is none');
    }

    public function testTheRunTakesTheRuleTintAndTheLegendReadsBright(): void
    {
        $styles = Styles::dark();
        $row = Rule::horizontal(10, $styles, 'Ab', Role::TabAccent);

        $this->assertStringStartsWith($styles->of(Role::TabAccent)->apply('├'), $row, 'the tee is the frame\'s');
        $this->assertStringContainsString($styles->of(Role::Rule)->apply('─ '), $row);
        $this->assertStringContainsString($styles->of(Role::Bright)->apply('Ab'), $row);
        $this->assertStringEndsWith($styles->of(Role::TabAccent)->apply('┤'), $row);
    }
}
