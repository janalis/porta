<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RendersExactWidth;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Density;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldSpec;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Support\OptionTagState;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The boxed text field: exact width at every size, the state told by the
 * border's shape (so it survives monochrome), the legend always on screen,
 * the placeholder gone at the first keystroke, and one caret marker at most.
 */
final class FieldBoxTest extends TestCase
{
    use RendersExactWidth;

    /** @return list<string> */
    private static function plain(array $rows): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $rows);
    }

    private static function field(string $value): TextField
    {
        $field = new TextField();
        $field->setValue($value);

        return $field;
    }

    public function testTheBoxIsALegendedFrameAroundTheValue(): void
    {
        $rows = self::plain(FieldBox::render(new FieldSpec('Find', trailing: '(Tab)'), self::field('foo'), FieldState::Idle, CaretVisibility::Hidden, 20, Styles::dark()));

        $this->assertSame(
            [
                '╭ Find ────────────╮',
                '│ foo              │',
                '╰────────── (Tab) ─╯',
            ],
            $rows,
            'the label is a legend in the top border, the hint set into the bottom one',
        );
    }

    public function testAControlIsBoxedOnlyOnARoomyScreenWithRoomForTheBox(): void
    {
        $this->assertSame(FieldBox::ROWS, FieldBox::rowsFor(Density::Roomy));
        $this->assertSame(FieldBox::ROWS, FieldBox::rowsFor(Density::Roomy, FieldBox::MIN_BOXED));
        $this->assertSame(1, FieldBox::rowsFor(Density::Roomy, FieldBox::MIN_BOXED - 1), 'narrower, a box is all border');
        $this->assertSame(1, FieldBox::rowsFor(Density::Compact), 'a compact screen takes every box back');
    }

    public function testAFieldTakesTheHeightItIsGiven(): void
    {
        $spec = new FieldSpec('Find');
        $field = self::field('foo');

        $this->assertSame(
            FieldBox::render($spec, $field, FieldState::Idle, CaretVisibility::Hidden, 20, Styles::dark()),
            FieldBox::field($spec, $field, FieldState::Idle, CaretVisibility::Hidden, 20, FieldBox::ROWS, Styles::dark()),
        );
        $this->assertSame(
            [FieldBox::compact($spec, $field, FieldState::Idle, CaretVisibility::Hidden, 20, Styles::dark())],
            FieldBox::field($spec, $field, FieldState::Idle, CaretVisibility::Hidden, 20, 1, Styles::dark()),
        );
    }

    /** The one-row toggle is a tag: bracketed on, padded off, both the glyph + 2 so no click zone moves. */
    public function testATagBracketsTheCaptionWhileOn(): void
    {
        $styles = Styles::dark();

        $this->assertSame([$styles->of(Role::SelectedRow)->apply('[C]')], FieldBox::toggle('C', OptionTagState::On, 1, $styles));
        $this->assertSame([$styles->of(Role::SelectedRow)->withUnderline()->apply('[C]')], FieldBox::toggle('C', OptionTagState::OnHovered, 1, $styles), 'lit, the pointer underlines');
        $this->assertSame([$styles->of(Role::RowHighlightBg)->apply(' C ')], FieldBox::toggle('C', OptionTagState::Hovered, 1, $styles));
        $this->assertSame([$styles->of(Role::Faint)->apply(' C ')], FieldBox::toggle('C', OptionTagState::Off, 1, $styles));
        $this->assertSame(3, FieldBox::toggleWidth('C', 1));
        $this->assertSame(OptionTagState::OnHovered, OptionTagState::of(on: true, hovered: true), 'on AND hovered is its own state');
    }

    /** Boxed, the frame is always idle: the caption carries the state, and reads under monochrome. */
    public function testABoxedToggleCarriesItsStateOnTheCaption(): void
    {
        $styles = Styles::dark();
        $on = FieldBox::toggle('.*', OptionTagState::On, FieldBox::ROWS, $styles);

        $this->assertSame(['╭────╮', '│ .* │', '╰────╯'], self::plain($on));
        $this->assertStringContainsString($styles->of(Role::SelectedRow)->apply('.*'), $on[1]);
        $this->assertStringContainsString(
            $styles->of(Role::Bright)->withUnderline()->apply('.*'),
            FieldBox::toggle('.*', OptionTagState::Hovered, FieldBox::ROWS, $styles)[1],
            'hover underlines, the border stays',
        );
        $this->assertStringContainsString($styles->of(Role::Faint)->apply('.*'), FieldBox::toggle('.*', OptionTagState::Off, FieldBox::ROWS, $styles)[1]);
        $this->assertSame(6, FieldBox::toggleWidth('.*', FieldBox::ROWS));
        self::assertExactWidth(FieldBox::toggle('.*', OptionTagState::OnHovered, FieldBox::ROWS, $styles), 6);

        $mono = new Styles(MonochromeTheme::definition());
        $this->assertNotSame(
            FieldBox::toggle('.*', OptionTagState::On, FieldBox::ROWS, $mono),
            FieldBox::toggle('.*', OptionTagState::Off, FieldBox::ROWS, $mono),
            'on and off stay apart without colour',
        );
    }

    public function testTheBorderShapeCarriesTheState(): void
    {
        $mono = new Styles(MonochromeTheme::definition());
        $shapes = [];
        foreach ([FieldState::Idle, FieldState::Focused, FieldState::Danger] as $state) {
            $shapes[$state->name] = self::plain(FieldBox::render(new FieldSpec('Find'), self::field('x'), $state, CaretVisibility::Hidden, 16, $mono));
        }

        $this->assertSame('╭', \mb_substr($shapes['Idle'][0], start: 0, length: 1));
        $this->assertSame('┃', \mb_substr($shapes['Focused'][1], start: 0, length: 1), 'focus is a heavy rail');
        $this->assertSame('╚', \mb_substr($shapes['Danger'][2], start: 0, length: 1), 'danger is a double line');
        $this->assertCount(3, \array_unique(\array_map(serialize(...), $shapes)), 'with no colour at all, every state still differs');
    }

    public function testTheBorderTintRepeatsTheStateWithoutBold(): void
    {
        $styles = Styles::dark();
        $focused = FieldBox::render(new FieldSpec(''), self::field('x'), FieldState::Focused, CaretVisibility::Hidden, 12, $styles)[0];
        $idle = FieldBox::render(new FieldSpec(''), self::field('x'), FieldState::Idle, CaretVisibility::Hidden, 12, $styles)[0];

        $this->assertStringContainsString($styles->of(Role::TabAccent)->apply('┏'), $focused, 'the accent repeats the shape');
        $this->assertStringContainsString($styles->of(Role::FieldBorder)->apply('╭'), $idle);
        $this->assertStringNotContainsString("\x1b[22m", $focused.$idle, 'and never bold: its SGR 22 would unbold the row a compact field sits in');
    }

    public function testThePlaceholderShowsUntilTheFirstKeystroke(): void
    {
        $styles = Styles::dark();
        $field = self::field('');
        $spec = new FieldSpec('Find', 'search term…');

        $before = FieldBox::render($spec, $field, FieldState::Focused, CaretVisibility::Shown, 24, $styles);
        $this->assertStringContainsString($styles->of(Role::FieldPlaceholder)->apply('search term…'), $before[1], 'shown while focused and empty, like a browser');
        $this->assertStringContainsString('Find', AnsiUtils::stripAnsiCodes($before[0]), 'and the label stays on screen beside it');

        $field->insert('a');
        $this->assertStringNotContainsString('search term', FieldBox::render($spec, $field, FieldState::Focused, CaretVisibility::Shown, 24, $styles)[1]);
    }

    public function testAnIdleFieldRefusesTheCaret(): void
    {
        $this->expectException(\LogicException::class);

        FieldBox::render(new FieldSpec('Find'), self::field('x'), FieldState::Idle, CaretVisibility::Shown, 20, Styles::dark());
    }

    public function testTheCaretMarkerAppearsExactlyOnce(): void
    {
        $rows = FieldBox::render(new FieldSpec('Find'), self::field('a long query that scrolls'), FieldState::Focused, CaretVisibility::Shown, 14, Styles::dark());

        $this->assertSame(1, \substr_count(\implode('', $rows), AnsiUtils::CURSOR_MARKER_PREFIX));
        $this->assertStringContainsString('scrolls', AnsiUtils::stripAnsiCodes($rows[1]), 'the caret end stays in view');
    }

    public function testAFocusedCaretlessFieldKeepsItsTailInView(): void
    {
        $row = self::plain(FieldBox::render(new FieldSpec('≡'), self::field('abcdefghijklmnop'), FieldState::Focused, CaretVisibility::Hidden, 10, Styles::dark()))[1];

        $this->assertStringContainsString('mnop', $row, 'the list filter shows where the typing lands');
    }

    public function testAnIdleFieldCutsItsValueWithAnEllipsis(): void
    {
        $row = self::plain(FieldBox::render(new FieldSpec(''), self::field('abcdefghijklmnop'), FieldState::Idle, CaretVisibility::Hidden, 10, Styles::dark()))[1];

        $this->assertSame('│ abcde… │', $row);
    }

    public function testAHintThatDoesNotFitIsDroppedWhole(): void
    {
        $rows = self::plain(FieldBox::render(new FieldSpec('F', trailing: '(Tab · Alt+A)'), self::field(''), FieldState::Idle, CaretVisibility::Hidden, 12, Styles::dark()));

        $this->assertSame('╰──────────╯', $rows[2]);
    }

    public function testTheSurfaceTintsEveryRow(): void
    {
        foreach (FieldBox::render(new FieldSpec('F', surface: Role::Toolbar), self::field(''), FieldState::Idle, CaretVisibility::Hidden, 12, Styles::dark()) as $row) {
            $this->assertStringStartsWith("\x1b[48;5;235m", $row, 'the dark toolbar background');
        }
    }

    public function testTheCompactFormKeepsTheRailShape(): void
    {
        $mono = new Styles(MonochromeTheme::definition());
        $idle = AnsiUtils::stripAnsiCodes(FieldBox::compact(new FieldSpec('Find'), self::field('foo'), FieldState::Idle, CaretVisibility::Hidden, 16, $mono));
        $focused = AnsiUtils::stripAnsiCodes(FieldBox::compact(new FieldSpec('Find'), self::field('foo'), FieldState::Focused, CaretVisibility::Shown, 16, $mono));

        $this->assertSame('Find │ foo     │', $idle);
        $this->assertSame('Find ┃ foo     ┃', $focused);
    }

    public function testTheCompactFormDropsItsLabelBeforeItsValue(): void
    {
        $this->assertSame('│ fo… │', AnsiUtils::stripAnsiCodes(FieldBox::compact(new FieldSpec('Replace'), self::field('foobar'), FieldState::Idle, CaretVisibility::Hidden, 7, Styles::dark())));
    }

    public function testTheCompactFormKeepsItsHintOnlyWhileTheValueHasRoom(): void
    {
        $spec = new FieldSpec('F', trailing: '(Tab)');

        $this->assertStringEndsWith('│ (Tab)', AnsiUtils::stripAnsiCodes(FieldBox::compact($spec, self::field(''), FieldState::Idle, CaretVisibility::Hidden, 20, Styles::dark())));
        $this->assertStringNotContainsString('(Tab)', AnsiUtils::stripAnsiCodes(FieldBox::compact($spec, self::field(''), FieldState::Idle, CaretVisibility::Hidden, 19, Styles::dark())));
    }

    public function testAFrameWrapsSeveralContentRows(): void
    {
        $rows = self::plain(FieldBox::frame(new FieldSpec('Description'), ['one', 'two'], FieldState::Idle, 20, Styles::dark()));

        $this->assertCount(4, $rows);
        $this->assertSame('│ two              │', $rows[2]);
    }

    public function testADisabledControlIsDashedAndNeverTakesKeys(): void
    {
        $rows = self::plain(FieldBox::button('Built-in', FieldState::Disabled, false, 14, FieldBox::ROWS, new Styles(MonochromeTheme::definition())));

        $this->assertSame(['╭┄┄┄┄┄┄┄┄┄┄┄┄╮', '┆  Built-in  ┆', '╰┄┄┄┄┄┄┄┄┄┄┄┄╯'], $rows, 'a dashed border reads "not available" without colour');
        $this->assertFalse(FieldState::Disabled->takesKeys());
        $this->assertSame(Role::Faint, FieldState::Disabled->borderRole());
    }

    public function testAButtonCentresItsCaptionAndHoverOnlyUnderlinesIt(): void
    {
        $styles = Styles::dark();
        $rest = FieldBox::button('Go', FieldState::Idle, false, 12, FieldBox::ROWS, $styles, trailing: 'Enter');
        $hover = FieldBox::button('Go', FieldState::Idle, true, 12, FieldBox::ROWS, $styles, trailing: 'Enter');

        $this->assertSame('│    Go    │', AnsiUtils::stripAnsiCodes($rest[1]), 'centred, as a browser button draws it');
        $this->assertStringContainsString('Enter', AnsiUtils::stripAnsiCodes($rest[2]), 'its key sits in the bottom border');
        $this->assertStringContainsString($styles->of(Role::Bright)->withUnderline()->apply('Go'), $hover[1]);
        $this->assertSame($rest[0], $hover[0], 'the border does not move on hover: hover is not a state');
        $this->assertStringContainsString($styles->of(Role::Faint)->apply('Go'), FieldBox::button('Go', FieldState::Disabled, true, 10, 1, $styles)[0], 'a disabled caption greys, hovered or not');
    }

    public function testASelectShowsItsValueThenTheArrowAgainstTheRail(): void
    {
        $width = FieldBox::selectWidth(['dark', 'high-contrast']);
        $rows = self::plain(FieldBox::select(new FieldSpec(''), 'dark', FieldState::Focused, $width, FieldBox::ROWS, Styles::dark()));

        $this->assertSame(19, $width, 'the widest option, the arrow and the chrome');
        $this->assertSame('┃ dark          ▾ ┃', $rows[1]);
        $this->assertSame(['│ dark      ▾ │'], self::plain(FieldBox::select(new FieldSpec(''), 'dark', FieldState::Idle, 15, 1, Styles::dark())), 'the one-row form keeps the arrow');
    }

    public function testControlWidthAndCentringMeasureInColumns(): void
    {
        $this->assertSame(10, FieldBox::controlWidth('漢字'), 'two wide glyphs, a column of air each side, the chrome');
        $this->assertSame('  ab  ', FieldBox::centred('ab', 6));
        $this->assertSame('abc…', FieldBox::centred('abcdef', 4), 'cut when it does not fit');
        $this->assertSame('', FieldBox::centred('ab', -3), 'no room is no text, not an error');
    }

    /** @return iterable<string, array{FieldState, CaretVisibility, string, int}> */
    public static function shapes(): iterable
    {
        foreach ([4, 5, 6, 11, 12, 40] as $width) {
            foreach (['', 'plain', '漢字漢字漢字漢字漢字漢字', 'a🙂b🙂c🙂d🙂e🙂f🙂g'] as $value) {
                yield "idle {$width} {$value}" => [FieldState::Idle, CaretVisibility::Hidden, $value, $width];
                yield "focused {$width} {$value}" => [FieldState::Focused, CaretVisibility::Shown, $value, $width];
                yield "danger {$width} {$value}" => [FieldState::Danger, CaretVisibility::Shown, $value, $width];
                yield "filter {$width} {$value}" => [FieldState::Focused, CaretVisibility::Hidden, $value, $width];
            }
        }
    }

    #[DataProvider('shapes')]
    public function testEveryRowIsExactlyTheRequestedWidth(FieldState $state, CaretVisibility $caret, string $value, int $width): void
    {
        $spec = new FieldSpec('Replace', 'placeholder text', '(Tab · Alt+A)');
        foreach ([0, 3, 6, \mb_strlen($value)] as $caretAt) {
            $field = self::field($value);
            $field->moveHome();
            for ($i = 0; $i < $caretAt; ++$i) {
                $field->moveRight();
            }

            self::assertExactWidth(FieldBox::render($spec, $field, $state, $caret, $width, Styles::dark()), $width, "box, caret at {$caretAt}");
            self::assertExactWidth([FieldBox::compact($spec, $field, $state, $caret, $width, Styles::dark())], $width, "compact, caret at {$caretAt}");
        }
    }

    /** @return iterable<string, array{bool|null, FieldState, string}> */
    public static function checkboxes(): iterable
    {
        yield 'checked, at rest' => [true, FieldState::Idle, '[✓]'];
        yield 'unchecked, at rest' => [false, FieldState::Idle, '[ ]'];
        yield 'partial, at rest' => [null, FieldState::Idle, '[–]'];
        yield 'taking the keys' => [true, FieldState::Focused, '┃✓┃'];
        yield 'disabled' => [false, FieldState::Disabled, '┆ ┆'];
        yield 'danger, should one ever be' => [true, FieldState::Danger, '║✓║'];
    }

    /**
     * A checkbox's state is its rails, the mark between them its value — and
     * three columns in every state, so nothing beside it moves on focus.
     */
    #[DataProvider('checkboxes')]
    public function testACheckboxIsItsRailsAndItsMark(?bool $checked, FieldState $state, string $expected): void
    {
        $this->assertSame($expected, FieldBox::checkbox($checked, $state));
        $this->assertSame(FieldBox::CHECKBOX_WIDTH, \mb_strwidth($expected));
    }

    /**
     * Tinted, the mark takes the accent — ✓ and –, never the blank — and the
     * rails stay bare; untinted, nothing but glyphs. The monochrome theme
     * keeps the accent plain, so the tint is a repeat of the shape, never
     * the only cue.
     */
    public function testATintColoursTheMarkAlone(): void
    {
        $accent = Styles::dark()->of(Role::TabAccent);

        $this->assertSame('['.$accent->apply('✓').']', FieldBox::checkbox(true, tint: Styles::dark()));
        $this->assertSame('['.$accent->apply('–').']', FieldBox::checkbox(null, tint: Styles::dark()));
        $this->assertSame('[ ]', FieldBox::checkbox(false, tint: Styles::dark()));
        $this->assertSame('┃'.$accent->apply('✓').'┃', FieldBox::checkbox(true, FieldState::Focused, Styles::dark()));
        $this->assertSame('[✓]', FieldBox::checkbox(true, tint: new Styles(MonochromeTheme::definition())));
    }

    /** @return iterable<string, array{bool|null, FieldState, list<string>}> */
    public static function boxedCheckboxes(): iterable
    {
        yield 'checked, at rest' => [true, FieldState::Idle, ['╭───╮', '│ ✓ │', '╰───╯']];
        yield 'unchecked, at rest' => [false, FieldState::Idle, ['╭───╮', '│   │', '╰───╯']];
        yield 'partial' => [null, FieldState::Idle, ['╭───╮', '│ – │', '╰───╯']];
        yield 'taking the keys' => [true, FieldState::Focused, ['┏━━━┓', '┃ ✓ ┃', '┗━━━┛']];
        yield 'disabled' => [false, FieldState::Disabled, ['╭┄┄┄╮', '┆   ┆', '╰┄┄┄╯']];
        yield 'danger' => [true, FieldState::Danger, ['╔═══╗', '║ ✓ ║', '╚═══╝']];
    }

    /**
     * Boxed, a checkbox is the same family as the boxes beside it: the
     * state's own corners and rails round the mark — rails even at rest,
     * where the one-row form has brackets — three rows of its one width.
     */
    #[DataProvider('boxedCheckboxes')]
    public function testABoxedCheckboxIsTheStatesFrameRoundTheMark(?bool $checked, FieldState $state, array $expected): void
    {
        $rows = FieldBox::checkboxBox($checked, $state, Styles::dark());

        $this->assertSame($expected, \array_map(AnsiUtils::stripAnsiCodes(...), $rows));
        $this->assertCount(FieldBox::ROWS, $rows);
        foreach ($rows as $row) {
            $this->assertSame(FieldBox::CHECKBOX_BOX_WIDTH, AnsiUtils::visibleWidth($row), 'its mark padded a column either side');
        }
    }

    /** The frame takes the state's border tint; the mark the accent, unless the row is selected. */
    public function testABoxedCheckboxTintsItsFrameAndOptionallyItsMark(): void
    {
        $accent = Styles::dark()->of(Role::TabAccent)->apply('✓');
        $border = Styles::dark()->of(FieldState::Focused->borderRole());

        $tinted = FieldBox::checkboxBox(true, FieldState::Focused, Styles::dark());
        $plain = FieldBox::checkboxBox(true, FieldState::Focused, Styles::dark(), tintMark: false);

        $this->assertSame($border->apply('┏━━━┓'), $tinted[0]);
        $this->assertStringContainsString($accent, $tinted[1]);
        $this->assertStringNotContainsString($accent, $plain[1]);
        $this->assertSame(['╭───╮', '│ ✓ │', '╰───╯'], FieldBox::checkboxBox(true, FieldState::Idle, new Styles(MonochromeTheme::definition())), 'monochrome: bare glyphs, the shape is the state');
    }
}
