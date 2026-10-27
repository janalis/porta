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
use Porta\Tests\Support\LongHintForm;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersWidget;
use Porta\Tests\Support\TestTui;
use Porta\Widget\Settings\SettingsFormWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\LineBufferInterface;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Text longer than its column wraps inside it: a label in the label column,
 * a detail in what the control leaves — in either direction, and again on
 * every frame a resized window offers a new width.
 */
final class SettingsFormWrapTest extends TestCase
{
    use RendersWidget;

    /**
     * A detail longer than the room beside its control wraps there, whole, and
     * the item grows to hold it — it used to be cut to `· a hint fa…`, the rest
     * only a tooltip away. Drawn whole, it needs no tooltip.
     */
    public function testALongDetailWrapsBesideItsControl(): void
    {
        $widget = LongHintForm::create();
        $rows = \explode("\n", $this->plain($widget->render(new RenderContext(50, 20))));

        $this->assertSame(
            ['· a hint far', '  too long to', '  fit beside', '  the field in', '  a narrow', '  pane'],
            \array_map(static fn (string $r): string => \rtrim(\mb_substr($r, start: 36)), \array_slice($rows, offset: 6, length: 6)),
            'one column of prose, opened by the dot and indented under it',
        );
        $this->assertStringContainsString('  Long     │                      │ · a hint far', $rows[6], 'opened beside the label, on the field\'s value row');
        $this->assertStringContainsString('Changed', $rows[13], 'the next item starts under the last line');
        $this->assertNull($widget->tooltipAt(6, 3), 'nothing was cut, so nothing to say');
        $this->assertSame([50], \array_unique(\array_map(AnsiUtils::visibleWidth(...), $rows)), 'every row the pane wide');
    }

    /**
     * A label longer than its column wraps inside it rather than shoving its
     * control off the column every other row keeps — padded, never cut, a long
     * label used to push the checkbox right and the row off the pane's edge.
     */
    public function testALabelLongerThanItsColumnWrapsInsideIt(): void
    {
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows([
            new SettingsRow(key: 'k', label: 'A very long label that will not fit at all', kind: SettingsRowKind::Bool, value: true, restart: false, default: false),
            new SettingsRow(key: 'short', label: 'Short', kind: SettingsRowKind::Bool, value: true, restart: false),
        ]);

        $screen = \array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(20, 10)));

        // 20 columns: the label column is ten wide, the one-row checkbox after
        // it; a changed row's ● marks its first line, not every line.
        $this->assertSame(
            ['● A very      [✓]   ', '  long label        ', '  that will         ', '  not fit at        ', '  all               ', '  Short       [✓]   '],
            \array_slice($screen, offset: 2, length: 6),
        );
    }

    /**
     * The window the form lives in is resized by its edge and its splitter,
     * so the wrap is the frame's, never the widget's: a narrower pane re-wraps
     * on its next frame, the selected item stays in view as the items above
     * it grow, and a wider one takes the rows back. Driven through the real
     * renderer, unforced, so a cached render would show.
     */
    public function testAResizedPaneRewrapsOnItsNextFrame(): void
    {
        $long = 'Runtime indexing (trusts the workspace: the server executes its PHP)';
        $rows = [new SettingsRow(key: 'runtime', label: $long, kind: SettingsRowKind::Bool, value: true, restart: false)];
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = new SettingsRow(key: "k{$i}", label: "Setting {$i}", kind: SettingsRowKind::Bool, value: true, restart: false);
        }
        $form = new SettingsFormWidget(new Translator('en'));
        $form->setRows($rows);
        $form->selectKey('k2');
        $tui = TestTui::create(220, 16);
        $terminal = $tui->getTerminal();
        $this->assertInstanceOf(VirtualTerminal::class, $terminal, 'the test rig\'s terminal');
        $tui->add(new ContainerWidget()->add($form));
        $frame = static function (int $columns) use ($tui, $terminal, $form): array {
            $terminal->simulateResize($columns, 16);
            $tui->requestRender();
            $tui->processRender();
            $lines = Probe::get($form, 'renderCacheLines');
            self::assertInstanceOf(LineBufferInterface::class, $lines);

            return \array_map(AnsiUtils::stripAnsiCodes(...), $lines->toArray());
        };

        $wide = $frame(220);
        $this->assertCount(
            1,
            \array_filter($wide, static fn (string $l): bool => \str_contains($l, 'Runtime indexing (trusts the workspace:')),
            'two rows in the forty columns a wide pane gives a label',
        );
        $this->assertCount(1, \array_filter($wide, static fn (string $l): bool => \str_contains($l, 'the server executes its PHP)')), 'and the rest on the next');

        $narrow = $frame(40);
        $this->assertNotSame([], \array_filter($narrow, static fn (string $l): bool => \str_contains($l, 'Setting 2')), 'the selection still in view');
        $this->assertSame([], \array_filter($narrow, static fn (string $l): bool => \str_contains($l, 'Runtime')), 'scrolled past the item that grew');
        $this->assertSame([40], \array_unique(\array_map(AnsiUtils::visibleWidth(...), $narrow)));

        $this->assertSame($wide, $frame(220), 'widened back: the first item scrolls back in, whole');
    }

    /**
     * Mirrored, wrapped text keeps to its side of the control on every row:
     * the label's lines right of the box, the detail's left of it, the box in
     * one column — a continuation row has no label run to anchor its marks.
     */
    public function testAMirroredWrapKeepsEachColumnOnItsSide(): void
    {
        $widget = new SettingsFormWidget(new Translator('he'));
        AttachedStyles::attach($widget);
        $widget->setRows([
            new SettingsRow(
                'runtime',
                'אינדוקס בזמן ריצה (סומך על סביבת העבודה: השרת מריץ את ה-PHP שלה)',
                SettingsRowKind::Bool,
                true,
                false,
                hint: 'השרת מפעיל את קוד ה-PHP של הפרויקט כדי לאנדקס אותו',
            ),
            new SettingsRow('short', 'קצר', SettingsRowKind::Bool, true, false),
        ], 'T');

        $rows = \array_map(
            static fn (string $line): string => AnsiUtils::stripAnsiCodes(Bidi::reorder($line, BaseDirection::Rtl)),
            $widget->render(new RenderContext(70, 24)),
        );

        $this->assertSame(
            [
                '                             ┏━━━┓',
                'לש PHP-ה דוק תא ליעפמ תרשה · ┃ ✓ ┃  תביבס לע ךמוס) הציר ןמזב סקודניא',
                '   ותוא סקדנאל ידכ טקיורפה   ┗━━━┛   (הלש PHP-ה תא ץירמ תרשה :הדובעה',
                '                             ╭───╮',
                '                             │ ✓ │                               רצק',
                '                             ╰───╯',
            ],
            \array_map(\rtrim(...), \array_slice($rows, offset: 2, length: 6)),
        );
    }

    /**
     * A wrapped detail row that starts in Latin keeps its side of the box: the
     * blank indent alone hands `PHP 8.5` to the control's left-to-right run,
     * and it was drawn across the box from the rest of its sentence.
     */
    public function testAMirroredDetailRowStartingInLatinStaysBesideTheBox(): void
    {
        $widget = new SettingsFormWidget(new Translator('he'));
        $widget->setRows([new SettingsRow('runtime', 'אינדוקס', SettingsRowKind::Bool, true, false, hint: 'השרת מפעיל את הקוד PHP 8.5 של הפרויקט')], 'T');

        $rows = \array_map(
            static fn (string $line): string => \rtrim(AnsiUtils::stripAnsiCodes(Bidi::reorder($line, BaseDirection::Rtl))),
            $widget->render(new RenderContext(40, 8)),
        );

        $this->assertSame('   טקיורפה לש PHP 8.5   ╰───╯', $rows[4]);
    }

    /**
     * With too little room beside its field, a hint wraps on the rows under
     * it, in its column, rather than being cut to `· e…` — a checkbox's hint,
     * with room beside its narrow box, stays there. Mirrored, it opens at the
     * box's start edge and flows away from it.
     */
    public function testAHintWithNoRoomBesideItsFieldWrapsUnderIt(): void
    {
        $rows = [
            new SettingsRow('en', 'Enabled', SettingsRowKind::Bool, true, false, hint: 'from global'),
            new SettingsRow('bin', 'symfony-lsp binary', SettingsRowKind::Text, '', false, hint: 'empty = PATH, then ~/.porta/bin (install it under Tools below)'),
            new SettingsRow('lang', 'Languages', SettingsRowKind::Text, 'php', false),
            new SettingsRow('rt', 'Runtime indexing (trusts the workspace: the server executes its PHP)', SettingsRowKind::Bool, false, false),
        ];
        $widget = new SettingsFormWidget(new Translator('en'));
        $widget->setRows($rows, 'T');
        $screen = \array_map(static fn (string $line): string => \rtrim(AnsiUtils::stripAnsiCodes($line)), $widget->render(new RenderContext(62, 20)));

        $this->assertSame(
            [
                '  Enabled                          │ ✓ │ · from global',
                '                                   ╰───╯',
                '                                   ╭──────────────────────╮',
                '  symfony-lsp binary               │                      │',
                '                                   ╰──────────────────────╯',
                '                                    · empty = PATH, then',
                '                                      ~/.porta/bin (install it',
                '                                      under Tools below)',
                '                                   ╭──────────────────────╮',
            ],
            \array_slice($screen, offset: 3, length: 9),
        );
        $this->assertNull($widget->tooltipAt(6, 3), 'drawn whole, so nothing to say');

        $mirrored = new SettingsFormWidget(new Translator('he'));
        $mirrored->setRows($rows, 'T');
        $screen = \array_map(
            static fn (string $line): string => \rtrim(AnsiUtils::stripAnsiCodes(Bidi::reorder($line, BaseDirection::Rtl))),
            $mirrored->render(new RenderContext(62, 20)),
        );
        $this->assertSame(['      empty = PATH, then ·', '~/.porta/bin (install it', '      under Tools below)'], \array_slice($screen, offset: 8, length: 3));

        // Narrower still, under the field is no wider than beside it: the
        // hint is cut beside its checkbox, and its tooltip says the rest.
        $narrow = new SettingsFormWidget(new Translator('en'));
        $narrow->setRows([new SettingsRow('flag', 'Flag', SettingsRowKind::Bool, true, false, hint: 'a hint far too long to fit')], 'T');
        $screen = \array_map(static fn (string $line): string => \rtrim(AnsiUtils::stripAnsiCodes($line)), $narrow->render(new RenderContext(20, 8)));
        $this->assertSame('  Flag  │ ✓ │ · a h…', $screen[3]);
        $this->assertSame('a hint far too long to fit', $narrow->tooltipAt(3, 3)?->text);
    }

    /**
     * A plugin's untranslated hint, wrapped in a mirrored form, keeps every
     * line's punctuation where its Latin puts it: each break is an edge, and
     * the edge's `~/.` or bracket took the form's direction — the drive drew
     * `porta/bin./~` and `install it under)` over `(Tools below`.
     */
    public function testAMirroredFormKeepsALatinHintsPunctuationOnEveryLine(): void
    {
        $widget = new SettingsFormWidget(new Translator('he'));
        $widget->setRows([new SettingsRow('bin', 'בינארי', SettingsRowKind::Text, '', false, hint: 'empty = PATH, then ~/.porta/bin (install it under Tools below)')], 'T');

        $rows = \array_map(
            static fn (string $line): string => \rtrim(AnsiUtils::stripAnsiCodes(Bidi::reorder($line, BaseDirection::Rtl))),
            $widget->render(new RenderContext(60, 8)),
        );

        $this->assertSame(
            ['     empty = PATH, then · │                      │  יראניב', '  ~/.porta/bin (install   ╰──────────────────────╯', '  it under Tools below)'],
            \array_slice($rows, offset: 3, length: 3),
        );

        $widget->setRows([new SettingsRow('rt', 'Runtime indexing (trusts the workspace: the server executes its PHP)', SettingsRowKind::Bool, true, false)], 'T');
        $label = \array_map(
            static fn (string $line): string => \rtrim(AnsiUtils::stripAnsiCodes(Bidi::reorder($line, BaseDirection::Rtl))),
            $widget->render(new RenderContext(44, 8)),
        );

        $this->assertSame('             │ ✓ │  (trusts the workspace:', $label[3], 'a label\'s lines too');
    }
}
