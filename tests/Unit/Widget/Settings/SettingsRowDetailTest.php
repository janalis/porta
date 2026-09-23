<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigScope;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Widget\Settings\SettingsRowDetail;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** The dim trailing clause: what a row is for, and what its control may become. */
final class SettingsRowDetailTest extends TestCase
{
    /**
     * Silent for the ordinary case — a value the project file sets and nothing
     * else. A tag on every row is a tag on no row.
     */
    public function testAnOrdinaryProjectValueIsNotTagged(): void
    {
        $this->assertSame('', $this->detail($this->row(SettingsRowKind::Bool, true, origin: ConfigScope::Project)));
    }

    /** A value coming from the file the user is not looking at says so. */
    public function testAGlobalValueSaysWhereItCameFrom(): void
    {
        $this->assertSame('from global', $this->detail($this->row(SettingsRowKind::Bool, true, origin: ConfigScope::Global)));
    }

    /**
     * The case the tag exists for: the screen shows the project's value while
     * a different one sits in the global file, and nothing else says so.
     */
    public function testAProjectValueHidingAGlobalOneSaysThat(): void
    {
        $row = $this->row(SettingsRowKind::Bool, true, origin: ConfigScope::Project, shadowed: true);

        $this->assertSame('overrides global', $this->detail($row));
    }

    /** Last of the clauses: a narrow row should lose this before the hint. */
    public function testTheOriginTrailsTheHint(): void
    {
        $row = $this->row(SettingsRowKind::Text, 'x', hint: 'why this exists', origin: ConfigScope::Global);

        $this->assertSame('why this exists · from global', $this->detail($row));
    }

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
        $this->assertSame('1-8', $this->detail($this->row(SettingsRowKind::Int, 4, min: 1, max: 8)));
    }

    public function testASingleSidedBoundShowsOnlyTheBoundThatExists(): void
    {
        $this->assertSame('≥ 0', $this->detail($this->row(SettingsRowKind::Int, 4, min: 0)));
        $this->assertSame('≤ 99', $this->detail($this->row(SettingsRowKind::Int, 4, max: 99)));
    }

    /**
     * A choice names no options: its dropdown shows every one, the way a
     * browser's `<select>` does, and a list after a `·` only crowded out the
     * hint a narrow row could still have shown.
     */
    public function testAChoiceListsNoOptionsBecauseItsDropdownShowsThem(): void
    {
        $row = new SettingsRow(key: 'k', label: 'L', kind: SettingsRowKind::Choice, value: 'dark', restart: false, options: ['dark', 'light', 'high-contrast'], hint: 'for this session');

        $this->assertSame('for this session', $this->detail($row));
    }

    public function testAnUnboundedIntShowsNoRange(): void
    {
        $this->assertSame('', $this->detail($this->row(SettingsRowKind::Int, 4)));
    }

    private function detail(SettingsRow $row): string
    {
        return SettingsRowDetail::of($row, new Translator('en'));
    }

    private function row(
        SettingsRowKind $kind,
        bool|int|string $value,
        bool $restart = false,
        ?string $hint = null,
        ?int $min = null,
        ?int $max = null,
        ?ConfigScope $origin = null,
        bool $shadowed = false,
    ): SettingsRow {
        return new SettingsRow(key: 'k', label: 'L', kind: $kind, value: $value, restart: $restart, min: $min, max: $max, hint: $hint, origin: $origin, shadowed: $shadowed);
    }

    /**
     * The range holds together on a mirrored screen.
     *
     * An en dash is an ordinary neutral, so UAX#9 never joins the numbers
     * either side of it: under a right-to-left base each takes the paragraph
     * direction between them and the two swap, and `1–8` reads `8–1`. A hyphen
     * is a separator, which W4 joins into the number itself.
     */
    public function testAnIntRangeDoesNotReverseWhenMirrored(): void
    {
        $row = new SettingsRow('indentSize', 'Indent size', SettingsRowKind::Int, 4, false, null, 1, 8);

        $detail = SettingsRowDetail::of($row, new Translator('he'));

        $this->assertStringContainsString('1-8', $detail, 'the bounds read low to high');
        $this->assertSame('1-8', self::strip(Bidi::reorder($detail, BaseDirection::Rtl)), 'and still do once mirrored');
    }

    /** ANSI codes stripped, for comparing a reordered string. */
    private static function strip(string $line): string
    {
        return AnsiUtils::stripAnsiCodes($line);
    }
}
