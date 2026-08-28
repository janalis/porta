<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Widget\Settings\SettingsRowLayout;

/** The label column every row on a page aligns its control against. */
final class SettingsRowLayoutTest extends TestCase
{
    public function testTheColumnFitsThePagesLongestLabel(): void
    {
        $layout = SettingsRowLayout::measure([
            $this->row('Theme'),
            $this->row('Skip images larger than'),
            $this->row('Tab width'),
        ], 80);

        $this->assertSame(mb_strwidth('Skip images larger than'), $layout->label);
    }

    /**
     * One pathological label must not push every value off the right edge, so
     * the column stops at half the pane and the label truncates instead.
     */
    public function testAPathologicalLabelIsCappedAtHalfThePane(): void
    {
        $layout = SettingsRowLayout::measure([$this->row(str_repeat('x', times: 200))], 60);

        $this->assertSame(30, $layout->label);
    }

    /** A narrow pane still leaves a usable column rather than collapsing to nothing. */
    public function testAVeryNarrowPaneKeepsAMinimumColumn(): void
    {
        $layout = SettingsRowLayout::measure([$this->row(str_repeat('x', times: 40))], 6);

        $this->assertSame(8, $layout->label);
    }

    public function testAnEmptyPageMeasuresNothing(): void
    {
        $this->assertSame(0, SettingsRowLayout::measure([], 80)->label);
    }

    /** Wide characters count by display width, not by code point. */
    public function testWideCharactersMeasureByDisplayWidth(): void
    {
        $layout = SettingsRowLayout::measure([$this->row('好好好')], 80);

        $this->assertSame(6, $layout->label, 'three double-width glyphs occupy six columns');
    }

    private function row(string $label): SettingsRow
    {
        return new SettingsRow(key: 'k', label: $label, kind: SettingsRowKind::Bool, value: true, restart: false);
    }
}
