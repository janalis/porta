<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Widget\Settings\SettingsRowDetail;

/** The dim trailing clause: what a row is for, and what its control may become. */
final class SettingsRowDetailTest extends TestCase
{
    public function testARowWithNothingToAddSaysNothing(): void
    {
        $this->assertSame('', $this->detail($this->row(SettingsRowKind::Bool, true)));
    }

    public function testTheRestartNoteLeadsAndTheHintFollows(): void
    {
        // Order is truncation order: the row cuts from the right, so the
        // consequential note must outlive the explanation.
        $row = $this->row(SettingsRowKind::Text, 'x', restart: true, hint: 'why this exists');

        $this->assertSame('applies on restart · why this exists', $this->detail($row));
    }

    public function testABoundedIntShowsItsRange(): void
    {
        $this->assertSame('1–8', $this->detail($this->row(SettingsRowKind::Int, 4, min: 1, max: 8)));
    }

    public function testASingleSidedBoundShowsOnlyTheBoundThatExists(): void
    {
        $this->assertSame('≥ 0', $this->detail($this->row(SettingsRowKind::Int, 4, min: 0)));
        $this->assertSame('≤ 99', $this->detail($this->row(SettingsRowKind::Int, 4, max: 99)));
    }

    public function testAnUnboundedIntShowsNoRange(): void
    {
        $this->assertSame('', $this->detail($this->row(SettingsRowKind::Int, 4)));
    }

    /** The current value is already in the control; the list says what ELSE. */
    public function testAChoiceListsTheOptionsItIsNotOn(): void
    {
        $row = new SettingsRow(key: 'k', label: 'L', kind: SettingsRowKind::Choice, value: 'dark', restart: false, options: ['dark', 'light', 'high-contrast']);

        $this->assertSame('light/high-contrast', $this->detail($row));
    }

    public function testAChoiceUsesItsOptionLabels(): void
    {
        $row = new SettingsRow(key: 'k', label: 'L', kind: SettingsRowKind::Choice, value: 'a', restart: false, options: ['a', ''], optionLabels: ['' => 'auto']);

        $this->assertSame('auto', $this->detail($row));
    }

    /** An option with neither a label nor a value of its own falls back to the raw value. */
    public function testAnUnlabelledEmptyOptionFallsBackToItsValue(): void
    {
        $row = new SettingsRow(key: 'k', label: 'L', kind: SettingsRowKind::Choice, value: 'a', restart: false, options: ['a', ''], optionLabels: ['' => '']);

        $this->assertSame('', $this->detail($row), 'an empty option with an empty label contributes an empty name');
    }

    public function testALongOptionListIsOmittedRatherThanCrowdingOutTheHint(): void
    {
        $row = new SettingsRow(
            key: 'k',
            label: 'L',
            kind: SettingsRowKind::Choice,
            value: 'a',
            restart: false,
            options: ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'],
            hint: 'the prose survives',
        );

        $this->assertSame('the prose survives', $this->detail($row), 'the stepper still walks them all');
    }

    public function testAChoiceOnItsOnlyOptionListsNothing(): void
    {
        $row = new SettingsRow(key: 'k', label: 'L', kind: SettingsRowKind::Choice, value: 'only', restart: false, options: ['only']);

        $this->assertSame('', $this->detail($row));
    }

    public function testAChoiceWithoutOptionsListsNothing(): void
    {
        $this->assertSame('', $this->detail($this->row(SettingsRowKind::Choice, 'x')));
    }

    private function detail(SettingsRow $row): string
    {
        return SettingsRowDetail::of($row, new Translator('en'));
    }

    private function row(SettingsRowKind $kind, bool|int|string $value, bool $restart = false, ?string $hint = null, ?int $min = null, ?int $max = null): SettingsRow
    {
        return new SettingsRow(key: 'k', label: 'L', kind: $kind, value: $value, restart: $restart, min: $min, max: $max, hint: $hint);
    }
}
