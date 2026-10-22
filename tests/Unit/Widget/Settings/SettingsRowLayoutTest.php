<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Ui\Density;
use Porta\Widget\Settings\SettingsRowLayout;

/** The label column every row on a page aligns its control against. */
final class SettingsRowLayoutTest extends TestCase
{
    public function testTheColumnFitsThePagesLongestLabel(): void
    {
        $layout = SettingsRowLayout::measure(
            [
                $this->row('Theme'),
                $this->row('Skip images larger than'),
                $this->row('Tab width'),
            ],
            80,
            Density::Roomy,
        );

        $this->assertSame(\mb_strwidth('Skip images larger than'), $layout->label);
    }

    /**
     * One pathological label must not push every value off the right edge:
     * the column stops at a third of the pane and the label wraps inside it,
     * leaving the control and its detail the rest. A comfortable label's 28
     * columns are kept whole while half the pane allows.
     */
    public function testAPathologicalLabelIsCappedAtAThirdOfThePane(): void
    {
        $label = $this->row(\str_repeat('word ', times: 40));

        $this->assertSame(40, SettingsRowLayout::measure([$label], 120, Density::Roomy)->label, 'a third');
        $this->assertSame(28, SettingsRowLayout::measure([$label], 60, Density::Roomy)->label, 'a comfortable label, past a third');
        $this->assertSame(20, SettingsRowLayout::measure([$label], 40, Density::Roomy)->label, 'never past half');
    }

    /** A narrow pane still leaves a usable column rather than collapsing to nothing. */
    public function testAVeryNarrowPaneKeepsAMinimumColumn(): void
    {
        $layout = SettingsRowLayout::measure([$this->row(\str_repeat('x', times: 40))], 6, Density::Roomy);

        $this->assertSame(8, $layout->label);
    }

    public function testAnEmptyPageMeasuresNothing(): void
    {
        $this->assertSame(0, SettingsRowLayout::measure([], 80, Density::Roomy)->label);
    }

    /** Wide characters count by display width, not by code point. */
    public function testWideCharactersMeasureByDisplayWidth(): void
    {
        $layout = SettingsRowLayout::measure([$this->row('好好好')], 80, Density::Roomy);

        $this->assertSame(6, $layout->label, 'three double-width glyphs occupy six columns');
    }

    /**
     * A boxed control is three rows, a checkbox one, and the first item of
     * each fieldset carries its legend's three rows (blank, rule, blank) —
     * so scrolling to it shows its legend too. A run of the same fieldset is
     * one group, one legend.
     */
    public function testHeightsCountBoxesCheckboxesAndLegends(): void
    {
        $layout = SettingsRowLayout::measure(
            [
                new SettingsRow('scope', 'Save to', SettingsRowKind::Choice, 'a', false, ['a']),
                new SettingsRow('a', 'A', SettingsRowKind::Bool, true, false, fieldset: 'Editor'),
                new SettingsRow('b', 'B', SettingsRowKind::Int, 4, false, max: 8, fieldset: 'Editor'),
                new SettingsRow('c', 'C', SettingsRowKind::Text, '', false, fieldset: 'Server'),
                new SettingsRow('d', 'D', SettingsRowKind::Action, 'Go', false),
            ],
            80,
            Density::Roomy,
        );

        $this->assertTrue($layout->boxed);
        $this->assertSame([3, 6, 3, 6, 3], $layout->heights, 'a checkbox is a box too: its legend\'s three rows and its own three');
        $this->assertSame([null, 'Editor', null, 'Server', null], $layout->legends);
        $this->assertSame(24, $layout->control, 'one width for the page: the text field\'s, the widest');
    }

    public function testAPaneTooNarrowForABoxDrawsEveryControlOnOneRow(): void
    {
        $layout = SettingsRowLayout::measure(
            [
                new SettingsRow('a', 'A label', SettingsRowKind::Choice, 'x', false, ['x'], fieldset: 'G'),
                new SettingsRow('b', 'B', SettingsRowKind::Bool, true, false, fieldset: 'G'),
            ],
            20,
            Density::Roomy,
        );

        $this->assertFalse($layout->boxed, '20 columns less the label leaves no room for a box');
        $this->assertSame([4, 1], $layout->heights, 'the legend still takes its three rows');
        $this->assertLessThanOrEqual(20 - SettingsRowLayout::LABEL_CHROME - $layout->label, $layout->control);
    }

    /** A compact screen takes every box back to one row, however wide the pane: one rule for every form. */
    public function testACompactScreenDrawsEveryControlOnOneRow(): void
    {
        $layout = SettingsRowLayout::measure(
            [
                new SettingsRow('a', 'A', SettingsRowKind::Choice, 'x', false, ['x'], fieldset: 'G'),
                new SettingsRow('b', 'B', SettingsRowKind::Bool, true, false, fieldset: 'G'),
            ],
            80,
            Density::Compact,
        );

        $this->assertFalse($layout->boxed);
        $this->assertSame([4, 1], $layout->heights);
    }

    /** Mirrored, only a label ending in punctuation takes a mark, and the mark costs the column nothing. */
    public function testAMirroredLabelEndingInPunctuationIsMarkedAndMeasured(): void
    {
        $this->assertSame('demo (project)'."\u{200E}", SettingsRowLayout::label('demo (project)', BaseDirection::Rtl));
        $this->assertSame('שרת LSP', SettingsRowLayout::label('שרת LSP', BaseDirection::Rtl), 'a letter closes its own run');
        $this->assertSame('demo (project)', SettingsRowLayout::label('demo (project)', BaseDirection::Ltr), 'unmirrored, never');
        $this->assertSame(14, SettingsRowLayout::measure([$this->row('demo (project)')], 80, Density::Roomy, BaseDirection::Rtl)->label);
        $this->assertSame(14, SettingsRowLayout::measure([$this->row('demo (project)')], 80, Density::Roomy)->label);
    }

    /**
     * A label longer than its column wraps inside it, centred on the control's
     * value row — one or two lines sit where an unwrapped label did, longer
     * ones start on the item's first row — and the item grows to hold it.
     */
    public function testALongLabelWrapsInItsColumnAndGrowsItsItem(): void
    {
        $layout = SettingsRowLayout::measure([$this->row('Runtime indexing (trusts the workspace: the server executes its PHP)'), $this->row('Short')], 40, Density::Roomy);

        $this->assertTrue($layout->boxed);
        $this->assertSame(20, $layout->label);
        $this->assertSame(['Runtime indexing', '(trusts the', 'workspace: the', 'server executes its', 'PHP)'], $layout->labels[0]);
        $this->assertSame(['Short'], $layout->labels[1]);
        $this->assertSame([5, 3], $layout->heights, 'five lines from the item\'s first row; a label that fits keeps the box\'s three');
    }

    /** A narrow column widens to its longest word, so a wrap never splits one — up to two thirds of the pane. */
    public function testANarrowColumnWidensToItsLongestWord(): void
    {
        $this->assertSame(10, SettingsRowLayout::measure([$this->row('Auto-pairs on')], 18, Density::Roomy)->label, 'half of 18 is nine; the word is ten');
        $this->assertSame([['Auto-pairs', 'on']], SettingsRowLayout::measure([$this->row('Auto-pairs on')], 18, Density::Roomy)->labels);
        $this->assertSame(12, SettingsRowLayout::measure([$this->row(\str_repeat('x', times: 20).' y')], 18, Density::Roomy)->label, 'never past two thirds');
    }

    /**
     * A word wider than its line breaks after its last hyphen or slash that
     * fits, not at whatever letter the width lands on — and only a word with
     * no such break is cut mid-letter.
     */
    public function testAnOverWideWordBreaksAtAHyphenOrSlash(): void
    {
        $text = new SettingsRow('k', 'Binary', SettingsRowKind::Text, '', false);
        $wrap = static fn (string $detail): ?array => SettingsRowLayout::measure([$text], 50, Density::Roomy, details: [$detail])->details[0];

        // 50 less the chrome, the six-column label, the field and ` · `: thirteen.
        $this->assertSame(['comma-', 'separated; *'], $wrap('comma-separated; *'));
        $this->assertSame(['~/.porta/bin/', 'symfony-lsp'], $wrap('~/.porta/bin/symfony-lsp'), 'the last break that fits');
        $this->assertSame(['~/.porta/', 'binaries'], $wrap('~/.porta/binaries'), 'a slash breaks too');
        $this->assertSame(['abcdefghijklm', 'nop'], $wrap('abcdefghijklmnop'), 'nowhere to break: cut');
        $this->assertSame(['-abcdefghijkl', 'mnop'], $wrap('-abcdefghijklmnop'), 'a leading hyphen leaves nothing before it to keep');
        $this->assertSame(
            [['abc-', 'defghijklmno', 'p']],
            SettingsRowLayout::measure([$this->row('abc-defghijklmnop')], 18, Density::Roomy)->labels,
            'a label too: its column stops at two thirds of the pane',
        );
    }

    public function testWrappedTextCentresOnTheValueRow(): void
    {
        $boxed = SettingsRowLayout::measure([$this->row('A')], 80, Density::Roomy);
        $this->assertSame([1, 1, 0, 0], [$boxed->top(1), $boxed->top(2), $boxed->top(3), $boxed->top(5)]);
        $this->assertSame([3], SettingsRowLayout::measure([$this->row('Fits on two lines here')], 34, Density::Roomy)->heights, 'two lines from the value row still fit the box');

        $oneRow = SettingsRowLayout::measure([$this->row('Wraps onto three lines')], 80, Density::Compact);
        $this->assertSame([0, 0], [$oneRow->top(1), $oneRow->top(3)]);
        $this->assertSame([1], $oneRow->heights);
        $this->assertSame([3], SettingsRowLayout::measure([$this->row('Wraps onto three lines')], 20, Density::Compact)->heights, 'the one-row form grows too');
    }

    /** The detail wraps in what its control leaves, and the item grows to hold it. */
    public function testADetailWrapsBesideItsControl(): void
    {
        $text = new SettingsRow('k', 'Binary', SettingsRowKind::Text, '', false);
        $detail = \implode(' ', \array_fill(0, count: 30, value: 'word'));

        $layout = SettingsRowLayout::measure([$text], 80, Density::Roomy, details: [$detail]);

        // 80 less the chrome, the six-column label, the 24-column field and ` · `.
        $this->assertSame([\implode(' ', \array_fill(0, count: 8, value: 'word'))], \array_unique(\array_slice($layout->details[0] ?? [], offset: 0, length: 3)));
        $this->assertCount(4, $layout->details[0] ?? []);
        $this->assertSame([5], $layout->heights, 'four lines from the value row, the box\'s second');
        $this->assertSame([[]], SettingsRowLayout::measure([$text], 80, Density::Roomy)->details, 'no detail, no lines');
    }

    /** Under {@see SettingsRowLayout::MIN_DETAIL} columns a wrapped detail is a tower: it keeps one cut row instead. */
    public function testADetailTooNarrowToWrapIsLeftToBeCut(): void
    {
        $text = new SettingsRow('k', 'Binary', SettingsRowKind::Text, '', false);
        $detail = 'a hint far too long to fit';

        $this->assertNull(SettingsRowLayout::measure([$text], 48, Density::Roomy, details: [$detail])->details[0]);
        $this->assertSame([3], SettingsRowLayout::measure([$text], 48, Density::Roomy, details: [$detail])->heights);
        $this->assertSame(['a hint far', 'too long to', 'fit'], SettingsRowLayout::measure([$text], 49, Density::Roomy, details: [$detail])->details[0]);
    }

    /** A checkbox leaves the detail what IT is drawn in, not the page's control width. */
    public function testACheckboxLeavesItsDetailTheRestOfTheRow(): void
    {
        $bool = $this->row('Binary');
        $detail = \str_repeat('x', times: 22).' y';

        $this->assertSame([\str_repeat('x', times: 22), 'y'], SettingsRowLayout::measure([$bool], 40, Density::Roomy, details: [$detail])->details[0], 'boxed: five columns');
        $this->assertSame([\str_repeat('x', times: 22).' y'], SettingsRowLayout::measure([$bool], 40, Density::Compact, details: [$detail])->details[0], 'one row: three');
    }

    /** A button whose caption is its own label leaves the label column blank. */
    public function testAButtonCaptionedWithItsLabelLeavesTheColumnBlank(): void
    {
        $layout = SettingsRowLayout::measure([new SettingsRow('k', 'Install phpstan', SettingsRowKind::Action, false, false)], 80, Density::Roomy);

        $this->assertSame([['']], $layout->labels);
    }

    private function row(string $label): SettingsRow
    {
        return new SettingsRow(key: 'k', label: $label, kind: SettingsRowKind::Bool, value: true, restart: false);
    }
}
