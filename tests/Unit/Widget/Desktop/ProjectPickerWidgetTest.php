<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Desktop;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Desktop\ProjectPickerWidget;
use Porta\Widget\Support\PickerRow;
use Porta\Widget\Support\PickerRowKind;
use Symfony\Component\Tui\Render\RenderContext;

/** How the picker's card is laid out, at the sizes a window can have. */
final class ProjectPickerWidgetTest extends TestCase
{
    use RendersWidget;

    private const string DOWN = "\x1b[B";

    /** @var list<array{?PickerRow, string}> */
    private array $submitted = [];

    /** @var list<PickerRow> */
    private array $rows = [];

    private function picker(): ProjectPickerWidget
    {
        return new ProjectPickerWidget(
            new Translator('en'),
            '1.2.3',
            rowsFor: fn (string $typed): array => $this->rows,
            onSubmit: function (ProjectPickerWidget $picker, ?PickerRow $row, string $typed): void {
                $this->submitted[] = [$row, $typed];
            },
            onTab: static fn (?PickerRow $row, string $typed): string => $typed.'/',
            onCancel: static function (): void {},
            footer: ['the keys'],
        );
    }

    private static function project(string $name): PickerRow
    {
        return new PickerRow(PickerRowKind::Project, $name, '~/'.$name, '/home/'.$name);
    }

    /** @return list<string> */
    private function screen(ProjectPickerWidget $picker, int $columns = 80, int $rows = 24): array
    {
        return $this->rows($picker->render(new RenderContext($columns, $rows)));
    }

    public function testTheCardIsCentredUnderPortasNameWithTheKeysAtTheBottom(): void
    {
        $this->rows = [PickerRow::heading('Recent'), self::project('shop')];

        $screen = $this->screen($this->picker());

        $this->assertCount(24, $screen);
        $this->assertSame(\str_repeat(' ', 34).'Porta 1.2.3', \rtrim($screen[1]), 'centred');
        $this->assertSame('A terminal IDE for PHP projects', \trim($screen[2]));
        $this->assertStringStartsWith('        ┏ Open a project', $screen[4]);
        $this->assertStringStartsWith('        ─ Recent ─', $screen[8]);
        $this->assertStringStartsWith('        ▌ shop  ~/shop', $screen[9]);
        $this->assertSame(\str_repeat(' ', 36).'the keys', \rtrim($screen[23]), 'centred');
    }

    public function testTheNameShowsFromSixteenRows(): void
    {
        $this->assertStringContainsString('Porta 1.2.3', \implode("\n", $this->screen($this->picker(), rows: 16)));
    }

    public function testAWindowTooShortForTheListIsCutToItsHeight(): void
    {
        $this->rows = [self::project('shop')];

        $this->assertCount(5, $this->screen($this->picker(), rows: 5));
    }

    public function testOnlyTheSelectedRowIsShadedAndEmphasised(): void
    {
        $this->rows = [self::project('one'), self::project('two')];
        $styles = Styles::dark();
        $shade = \strstr($styles->of(Role::SelectedRow)->apply('x'), 'x', before_needle: true);
        $bold = \strstr($styles->of(Role::Bright)->withBold()->apply('x'), 'x', before_needle: true);

        $lines = $this->picker()->render(new RenderContext(80, 24));

        $this->assertStringContainsString((string) $shade, $lines[8]);
        $this->assertStringContainsString((string) $bold, $lines[8]);
        $this->assertStringNotContainsString((string) $shade, $lines[9]);
        $this->assertStringNotContainsString((string) $bold, $lines[9]);
        $dim = (string) \strstr($styles->of(Role::Dim)->apply('x'), 'x', before_needle: true);
        $this->assertStringNotContainsString($dim.'~/one', $lines[8], 'the selection\'s hint is not dimmed into its shade');
        $this->assertStringContainsString($dim.'~/two', $lines[9]);
    }

    public function testUpAndPageDownMoveTheSelection(): void
    {
        $this->rows = [self::project('one'), self::project('two'), self::project('three')];
        $picker = $this->picker();
        $this->screen($picker);

        $picker->handleInput("\x1b[6~");
        $picker->handleInput("\x1b[A");
        $picker->handleInput("\r");

        $this->assertSame([[$this->rows[1], '']], $this->submitted);
    }

    public function testTypingPutsTheSelectionBackOnTheFirstRow(): void
    {
        $this->rows = [self::project('one'), self::project('two')];
        $picker = $this->picker();

        $picker->handleInput(self::DOWN);
        $picker->handleInput('o');
        $picker->handleInput("\r");

        $this->assertSame([[$this->rows[0], 'o']], $this->submitted);
    }

    public function testAShortWindowGivesTheNameUpToTheList(): void
    {
        $this->rows = [self::project('shop')];

        $screen = $this->screen($this->picker(), rows: 10);

        $this->assertStringContainsString('Open a project', $screen[0]);
        $this->assertStringNotContainsString('Porta 1.2.3', \implode("\n", $screen));
    }

    public function testANoteIsDimTextAndAPlainHeadingIsARule(): void
    {
        $this->rows = [PickerRow::note('Nothing here'), PickerRow::heading(''), self::project('shop')];

        $screen = $this->screen($this->picker());

        $this->assertSame('         Nothing here', \rtrim($screen[8]));
        $this->assertSame(\str_repeat('─', 64), \trim($screen[9]));
    }

    public function testALongNameIsCutAndTheHintsStayInOneColumn(): void
    {
        $this->rows = [self::project(\str_repeat('n', 40)), self::project('ab')];

        $screen = $this->screen($this->picker());

        $this->assertSame(\mb_strpos($screen[8], '~/'), \mb_strpos($screen[9], '~/ab'));
        $this->assertStringContainsString('…', $screen[8]);
    }

    public function testTheArrowsSkipHeadingsAndEnterHandsBackTheRow(): void
    {
        $this->rows = [PickerRow::heading('Recent'), self::project('one'), PickerRow::heading('Found'), self::project('two')];
        $picker = $this->picker();

        $picker->handleInput(self::DOWN);
        $picker->handleInput("\r");

        $this->assertSame([[$this->rows[3], '']], $this->submitted);
    }

    public function testWithNothingToChooseEnterHandsBackNoRow(): void
    {
        $this->rows = [PickerRow::note('Nothing here')];
        $picker = $this->picker();

        $picker->handleInput("\r");

        $this->assertSame([[null, '']], $this->submitted);
    }

    public function testTheListScrollsAndKeepsASectionsHeadingWithItsFirstRow(): void
    {
        $this->rows = [PickerRow::heading('Recent'), ...\array_map(self::project(...), \range('a', 'l')), PickerRow::heading('Found'), self::project('zz')];
        $picker = $this->picker();
        $this->screen($picker, rows: 20);

        for ($i = 0; $i < 12; ++$i) {
            $picker->handleInput(self::DOWN);
        }
        $screen = \implode("\n", $this->screen($picker, rows: 20));

        $this->assertStringContainsString('─ Found ─', $screen);
        $this->assertStringContainsString('▌ zz', $screen);
        $this->assertStringNotContainsString('─ Recent ─', $screen);

        // A page is the ten rows the list area had: from l back to c, then to a and its heading.
        $picker->handleInput("\x1b[5~");
        $this->assertStringContainsString('▌ c', \implode("\n", $this->screen($picker, rows: 20)));
        $picker->handleInput("\x1b[5~");
        $this->assertStringContainsString('─ Recent ─', \implode("\n", $this->screen($picker, rows: 20)), 'the first row is never shown without its heading');
    }

    public function testTypingListsAfreshAndARefusalShowsUntilTheTextChanges(): void
    {
        $this->rows = [self::project('shop')];
        $picker = $this->picker();

        $picker->refuse('Not a folder: x');
        $this->assertStringContainsString('Not a folder: x', $this->screen($picker)[7]);

        $picker->handleInput('s');
        $this->assertSame('', \trim($this->screen($picker)[7]));
        $this->assertStringContainsString('┃ s', $this->screen($picker)[5]);
    }

    public function testTabBackspaceAndClearEditTheField(): void
    {
        $picker = $this->picker();

        $picker->handleInput('a');
        $picker->handleInput("\t");
        $this->assertStringContainsString('┃ a/', $this->screen($picker)[5]);

        $picker->handleInput("\x7f");
        $this->assertStringContainsString('┃ a ', $this->screen($picker)[5]);

        $picker->handleInput("\x15");
        $this->assertStringContainsString('Type a name or a path', $this->screen($picker)[5]);
    }

    /** An SGR mouse report at the 0-based screen $line. */
    private static function mouse(int $code, int $line, string $end = 'M'): string
    {
        return "\x1b[<{$code};20;".($line + 1).$end;
    }

    public function testAClickOnARowChoosesIt(): void
    {
        $this->rows = [PickerRow::heading('Recent'), self::project('one'), self::project('two')];
        $picker = $this->picker();
        $this->screen($picker);

        $picker->handleInput(self::mouse(0, 10));

        $this->assertSame([[$this->rows[2], '']], $this->submitted);
    }

    public function testAClickOnAHeadingTheFieldOrBelowTheListIsNothing(): void
    {
        $this->rows = [PickerRow::heading('Recent'), self::project('one')];
        $picker = $this->picker();
        $this->screen($picker);

        $picker->handleInput(self::mouse(0, 8));
        $picker->handleInput(self::mouse(0, 5));
        $picker->handleInput(self::mouse(0, 15));

        $this->assertSame([], $this->submitted);
    }

    public function testOnlyALeftPressChooses(): void
    {
        $this->rows = [self::project('one')];
        $picker = $this->picker();
        $this->screen($picker);

        $picker->handleInput(self::mouse(2, 8));
        $picker->handleInput(self::mouse(0, 8, 'm'));
        $picker->handleInput(self::mouse(32, 8));

        $this->assertSame([], $this->submitted, 'a right click, a release and a drag are not choices');
    }

    public function testTheWheelMovesTheSelection(): void
    {
        $this->rows = [self::project('one'), self::project('two'), self::project('three')];
        $picker = $this->picker();
        $this->screen($picker);

        $picker->handleInput(self::mouse(65, 8));
        $picker->handleInput(self::mouse(65, 8));
        $picker->handleInput(self::mouse(64, 8));
        $picker->handleInput("\r");

        $this->assertSame([[$this->rows[1], '']], $this->submitted);
    }

    public function testEscapeCancels(): void
    {
        $cancelled = false;
        $picker = new ProjectPickerWidget(
            new Translator('en'),
            '',
            rowsFor: static fn (string $typed): array => [],
            onSubmit: static function (): void {},
            onTab: static fn (?PickerRow $row, string $typed): string => $typed,
            onCancel: static function () use (&$cancelled): void {
                $cancelled = true;
            },
            footer: [],
        );

        $picker->handleInput("\x1b");

        $this->assertTrue($cancelled);
    }

    public function testAnUnboundKeyChangesNothing(): void
    {
        $this->rows = [self::project('shop')];
        $picker = $this->picker();
        $before = $this->screen($picker);

        $picker->handleInput("\x1b[15~");

        $this->assertSame($before, $this->screen($picker));
    }
}
