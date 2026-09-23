<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
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

        $this->assertSame(\mb_strwidth('Skip images larger than'), $layout->label);
    }

    /**
     * One pathological label must not push every value off the right edge, so
     * the column stops at half the pane and the label truncates instead.
     */
    public function testAPathologicalLabelIsCappedAtHalfThePane(): void
    {
        $layout = SettingsRowLayout::measure([$this->row(\str_repeat('x', times: 200))], 60);

        $this->assertSame(30, $layout->label);
    }

    /** A narrow pane still leaves a usable column rather than collapsing to nothing. */
    public function testAVeryNarrowPaneKeepsAMinimumColumn(): void
    {
        $layout = SettingsRowLayout::measure([$this->row(\str_repeat('x', times: 40))], 6);

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

    /**
     * A boxed control is three rows, a checkbox one, and the first item of
     * each fieldset carries its legend's row — so scrolling to it shows its
     * legend too. A run of the same fieldset is one group, one legend.
     */
    public function testHeightsCountBoxesCheckboxesAndLegends(): void
    {
        $layout = SettingsRowLayout::measure([
            new SettingsRow('scope', 'Save to', SettingsRowKind::Choice, 'a', false, ['a']),
            new SettingsRow('a', 'A', SettingsRowKind::Bool, true, false, fieldset: 'Editor'),
            new SettingsRow('b', 'B', SettingsRowKind::Int, 4, false, max: 8, fieldset: 'Editor'),
            new SettingsRow('c', 'C', SettingsRowKind::Text, '', false, fieldset: 'Server'),
            new SettingsRow('d', 'D', SettingsRowKind::Action, 'Go', false),
        ], 80);

        $this->assertTrue($layout->boxed);
        $this->assertSame([3, 2, 3, 4, 3], $layout->heights);
        $this->assertSame([null, 'Editor', null, 'Server', null], $layout->legends);
        $this->assertSame(24, $layout->control, 'one width for the page: the text field\'s, the widest');
    }

    public function testAPaneTooNarrowForABoxDrawsEveryControlOnOneRow(): void
    {
        $layout = SettingsRowLayout::measure([
            new SettingsRow('a', 'A label', SettingsRowKind::Choice, 'x', false, ['x'], fieldset: 'G'),
            new SettingsRow('b', 'B', SettingsRowKind::Bool, true, false, fieldset: 'G'),
        ], 20);

        $this->assertFalse($layout->boxed, '20 columns less the label leaves no room for a box');
        $this->assertSame([2, 1], $layout->heights, 'the legend still takes its row');
        $this->assertLessThanOrEqual(20 - SettingsRowLayout::LABEL_CHROME - $layout->label, $layout->control);
    }

    /** Mirrored, only a label ending in punctuation takes a mark, and the column counts it. */
    public function testAMirroredLabelEndingInPunctuationIsMarkedAndMeasured(): void
    {
        $this->assertSame('demo (project)'."\u{200E}", SettingsRowLayout::label('demo (project)', BaseDirection::Rtl));
        $this->assertSame('שרת LSP', SettingsRowLayout::label('שרת LSP', BaseDirection::Rtl), 'a letter closes its own run');
        $this->assertSame('demo (project)', SettingsRowLayout::label('demo (project)', BaseDirection::Ltr), 'unmirrored, never');
        $this->assertSame(15, SettingsRowLayout::measure([$this->row('demo (project)')], 80, BaseDirection::Rtl)->label);
        $this->assertSame(14, SettingsRowLayout::measure([$this->row('demo (project)')], 80)->label);
    }

    private function row(string $label): SettingsRow
    {
        return new SettingsRow(key: 'k', label: $label, kind: SettingsRowKind::Bool, value: true, restart: false);
    }
}
