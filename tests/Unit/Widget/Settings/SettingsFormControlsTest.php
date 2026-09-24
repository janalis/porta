<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersExactWidth;
use Porta\Widget\Settings\SettingsFormWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The settings form as a browser form: selects that drop a list, steppers
 * with clickable arrows, buttons that can be disabled, fieldset legends —
 * and items several rows tall, which every click, hover and page goes
 * through the drawn row map to reach.
 */
final class SettingsFormControlsTest extends TestCase
{
    use RendersExactWidth;

    /** @var list<array{string, bool|int|string}> */
    private array $changes = [];

    /** @var list<array{string, int, int, int, int}> */
    private array $opened = [];

    /** @return list<SettingsRow> */
    private static function page(): array
    {
        return [
            new SettingsRow(key: 'theme', label: 'Theme', kind: SettingsRowKind::Choice, value: 'dark', restart: false, options: ['dark', 'light'], fieldset: 'Display'),
            new SettingsRow(key: 'sticky', label: 'Sticky scroll', kind: SettingsRowKind::Bool, value: true, restart: false, fieldset: 'Editor'),
            new SettingsRow(key: 'tab', label: 'Tab width', kind: SettingsRowKind::Int, value: 4, restart: false, min: 1, max: 8, fieldset: 'Editor'),
            new SettingsRow(key: 'builtin', label: 'git (built-in)', kind: SettingsRowKind::Action, value: 'Built-in', restart: false, fieldset: 'Installed', disabled: true),
        ];
    }

    /** @param list<SettingsRow>|null $rows */
    // @mago-expect lint:no-boolean-flag-parameter -- a fixture switch: the form with or without a host to show a list.
    private function form(?array $rows = null, bool $withList = true, string $locale = 'en'): SettingsFormWidget
    {
        $form = new SettingsFormWidget(new Translator($locale));
        AttachedStyles::attach($form);
        $form->setFocused(true);
        $form->setRows($rows ?? self::page());
        $form->onChange(function (string $key, bool|int|string $value): bool {
            $this->changes[] = [$key, $value];

            return true;
        });
        if ($withList) {
            $form->onOpenChoice(function (SettingsRow $row, int $below, int $col, int $above, int $width): void {
                $this->opened[] = [$row->key, $below, $col, $above, $width];
            });
        }

        return $form;
    }

    /**
     * Rows as drawn: 0 title · 1 `─ Display` · 2-4 Theme · 5 `─ Editor` ·
     * 6-8 Sticky · 9-11 Tab width · 12 `─ Installed` · 13-15 Built-in · footer.
     *
     * @return list<string>
     */
    private static function draw(SettingsFormWidget $form, int $rows = 18): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $form->render(new RenderContext(60, $rows)));
    }

    public function testFieldsetsHeadTheirGroupsOnceAndControlsAreBoxed(): void
    {
        $screen = self::draw($this->form());

        $this->assertStringStartsWith('─ Display ─', $screen[1]);
        $this->assertStringStartsWith('─ Editor ─', $screen[5]);
        $this->assertCount(1, \array_filter($screen, static fn (string $line): bool => \str_starts_with($line, '─ Editor')), 'a legend heads its group once, not every row in it');
        $this->assertStringContainsString('┏', $screen[2], 'the selected select is heavy-bordered');
        $this->assertMatchesRegularExpression('/Theme\s+┃ dark\s+▾ ┃/u', $screen[3], 'the label sits on the control\'s middle row');
        $this->assertMatchesRegularExpression('/^\s+╭─╮/u', $screen[6], 'a checkbox is a box of its own, like its neighbours');
        $this->assertMatchesRegularExpression('/Sticky scroll\s+│✓│/u', $screen[7], 'its label on its middle row');
        $this->assertMatchesRegularExpression('/^\s+╰─╯/u', $screen[8]);
        $this->assertSame(\mb_strpos($screen[2], needle: '┏'), \mb_strpos($screen[6], needle: '╭'), 'in the one control column');
        $this->assertMatchesRegularExpression('/Tab width\s+│ ◂\s+4\s+▸ │ · 1-8/u', $screen[10]);
        $this->assertMatchesRegularExpression('/git \(built-in\)\s+┆\s+Built-in\s+┆/u', $screen[14], 'a disabled button is dashed');
    }

    public function testEnterOnASelectOpensItsListUnderTheBoxAndArrowsStillStep(): void
    {
        $form = $this->form();
        self::draw($form);

        $form->handleInput("\r");
        $this->assertSame([['theme', 5, 18, 2, 14]], $this->opened, 'below the box (row 5), at its column (marker, 14-column label, gap), flipping above its top (row 2), the page\'s control width');
        $this->assertSame([], $this->changes, 'opening writes nothing');

        $form->handleInput(' ');
        $this->assertCount(2, $this->opened, 'Space opens it too');

        $form->handleInput("\x1b[C");
        $form->handleInput("\x1b[D");
        $this->assertSame([['theme', 'light'], ['theme', 'light']], $this->changes, '→ and ← step without opening (the row is not re-fed here, so both step from dark)');
    }

    public function testWithNoListToShowASelectSteps(): void
    {
        $form = $this->form(withList: false);
        self::draw($form);

        $form->handleInput("\r");

        $this->assertSame([['theme', 'light']], $this->changes, 'the row still moves');
    }

    public function testAClickOnASelectsBoxOpensItAtOnceButOnItsLabelSelectsFirst(): void
    {
        $form = $this->form();
        self::draw($form);
        $form->handleInput("\x1b[B"); // Sticky scroll

        $form->handleClick(3, 5); // Theme's label
        $this->assertSame(0, Probe::object($form, 'list')->selected(), 'the label selects');
        $this->assertSame([], $this->opened, 'and opens nothing on a first click');

        $form->handleInput("\x1b[B");
        self::draw($form);
        $form->handleClick(2, 25); // Theme's box, top border: opening writes nothing, so it needs no selecting first
        $this->assertSame(0, Probe::object($form, 'list')->selected());
        $this->assertCount(1, $this->opened);
    }

    public function testAStepperStepsByTheArrowHalfItIsClickedIn(): void
    {
        $form = $this->form();
        self::draw($form);

        $form->handleClick(10, 20); // Tab width's left half — unselected, so it only selects
        $this->assertSame([], $this->changes);

        self::draw($form);
        $form->handleClick(10, 20); // ◂ half
        $form->handleClick(10, 40); // ▸ half
        $form->handleClick(10, 3); // its label: the forward action
        $this->assertSame([['tab', 3], ['tab', 5], ['tab', 5]], $this->changes);
    }

    public function testADisabledItemIsSelectableButNeverActs(): void
    {
        $form = $this->form();
        self::draw($form);

        $form->handleClick(14, 3);
        $form->handleClick(14, 3);
        $form->handleInput("\r");
        $form->handleInput("\x1b[C");

        $this->assertSame(3, Probe::object($form, 'list')->selected(), 'the pointer and the keys can reach it');
        $this->assertSame([], $this->changes, 'a disabled button never presses');
    }

    public function testADisabledSelectOpensNoList(): void
    {
        $form = $this->form([new SettingsRow(key: 'k', label: 'K', kind: SettingsRowKind::Choice, value: 'a', restart: false, options: ['a', 'b'], disabled: true)]);
        self::draw($form);

        $form->handleInput("\r");

        $this->assertSame([], $this->opened);
        $this->assertSame([], $this->changes);
    }

    public function testLegendAndPaddingRowsBelongToNoItem(): void
    {
        $form = $this->form();
        $form->handleInput("\x1b[B"); // Sticky scroll
        self::draw($form, 22);

        $form->handleClick(5, 3); // the Editor legend
        $form->handleClick(5, 3);
        $form->handleClick(18, 3); // padding below the last item

        $this->assertSame(1, Probe::object($form, 'list')->selected(), 'no click on a legend or padding moved the selection');
        $this->assertSame([], $this->changes);
        $this->assertFalse($form->hoverRow(12), 'the Installed legend does not tint');
        $this->assertTrue($form->hoverRow(14), 'the item below it does');
    }

    public function testPageKeysMoveByTheItemsThatFit(): void
    {
        $rows = [];
        for ($i = 0; $i < 12; ++$i) {
            $rows[] = new SettingsRow(key: "k{$i}", label: "Setting {$i}", kind: SettingsRowKind::Choice, value: 'a', restart: false, options: ['a', 'b']);
        }
        $form = $this->form($rows);
        self::draw($form, 11); // nine rows for items: three boxes

        $form->handleInput("\x1b[6~"); // page down

        $this->assertSame(3, Probe::object($form, 'list')->selected(), 'a page is the three items on screen, not nine rows of them');
    }

    public function testANarrowPaneDrawsEveryControlOnOneRow(): void
    {
        $screen = self::draw($this->form(), 10);

        // 60 columns less the 4 of chrome and a 30-column label cap leaves
        // room for a box; 20 columns does not.
        $narrow = \array_map(AnsiUtils::stripAnsiCodes(...), $this->form()->render(new RenderContext(20, 10)));
        $this->assertStringContainsString('┏', $screen[2], 'boxed where it fits');
        $this->assertStringNotContainsString('┏', \implode("\n", $narrow), 'no border rows when it does not');
        $this->assertMatchesRegularExpression('/Theme\s+┃ \S* *▾ ┃/u', $narrow[2], 'the select, on its label row');
        $this->assertMatchesRegularExpression('/Sticky scroll\s+\[✓\]/u', $narrow[4], 'and a checkbox falls back to its one-row form, as every control does');
    }

    public function testEveryRowIsExactlyThePaneWide(): void
    {
        foreach ([8, 14, 20, 33, 60, 120] as $width) {
            foreach (['en', 'he'] as $locale) {
                self::assertExactWidth($this->form(locale: $locale)->render(new RenderContext($width, 16)), $width, "{$locale} at {$width}");
            }
        }
    }

    /**
     * Mirrored, a box still stands in one column: its top corner, the rail
     * beside its label and its bottom corner. The label row is the one that
     * carries direction marks and Hebrew; the border rows carry neither, so
     * any drift between them breaks the box apart.
     */
    public function testAMirroredBoxKeepsItsColumns(): void
    {
        $form = $this->form([
            new SettingsRow(key: 'theme', label: 'ערכת נושא', kind: SettingsRowKind::Choice, value: 'dark', restart: false, options: ['dark', 'light'], hint: 'עיצוב'),
            new SettingsRow(key: 'tab', label: 'רוחב טאב', kind: SettingsRowKind::Int, value: 4, restart: false, min: 1, max: 8),
            new SettingsRow(key: 'sticky', label: 'גלילה דביקה', kind: SettingsRowKind::Bool, value: true, restart: false),
        ], locale: 'he');
        $screen = \array_map(static fn (string $line): string => AnsiUtils::stripAnsiCodes(Bidi::reorder($line, BaseDirection::Rtl)), $form->render(new RenderContext(60, 13)));

        // The column of the first of $glyphs on a line, -1 when there is none.
        $column = static function (string $line, string $glyphs): int {
            $match = [];

            return 1 === \preg_match('/['.$glyphs.']/u', $line, $match, \PREG_OFFSET_CAPTURE) ? \mb_strlen(\substr($line, offset: 0, length: $match[0][1])) : -1;
        };
        foreach ([1, 4, 7] as $top) { // the select, the stepper, and the checkbox's own box
            $corner = $column($screen[$top], glyphs: '┏╭');
            $rail = $column($screen[$top + 1], glyphs: '┃│');
            $bottom = $column($screen[$top + 2], glyphs: '┗╰');
            $this->assertNotSame(-1, $corner);
            $this->assertSame($corner, $rail, "item at row {$top}: the label row's rail stands under the corner");
            $this->assertSame($corner, $bottom, "item at row {$top}: and over the bottom corner");
        }
    }
}
