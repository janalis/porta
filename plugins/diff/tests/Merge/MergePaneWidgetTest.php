<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests\Merge;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\Merge\MergePaneWidget;
use Porta\Editor\ConflictSide;
use Porta\I18n\Translator;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\ViewerTabHandler;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * One side of the merge view, driven through the closures it is handed: what
 * it draws level with the editor, what a click and a key do to the result,
 * and what the status says.
 */
final class MergePaneWidgetTest extends TestCase
{
    private const string RESULT = "a\n<<<<<<< HEAD\nours\n=======\ntheirs\n>>>>>>> topic\nb\n<<<<<<< HEAD\no2\n=======\nt2\n>>>>>>> topic";

    /** What the editor holds — the closures read and write it. */
    private ?string $editorText = self::RESULT;

    private int $caretLine = 0;
    private int $top = 0;

    /** @var list<string> */
    private array $calls = [];

    private function pane(ConflictSide $side, ?MergePaneWidget $ours = null): MergePaneWidget
    {
        return new MergePaneWidget(
            $side,
            new Translator('en'),
            fn (): ?string => $this->editorText,
            fn (): ?array => null === $this->editorText ? null : ['line' => $this->caretLine, 'column' => 0, 'top' => $this->top],
            function (string $text): void {
                $this->calls[] = 'replace';
                $this->editorText = $text;
            },
            function (int $line): void {
                $this->calls[] = 'jump:'.$line;
                $this->caretLine = $line;
            },
            function (int $rows): void {
                $this->calls[] = 'scroll:'.$rows;
            },
            Styles::dark(),
            $ours,
        );
    }

    private function shown(ConflictSide $side): MergePaneWidget
    {
        $pane = $this->pane($side);
        $pane->show('/p/m.php', self::RESULT);

        return $pane;
    }

    /** @return list<string> */
    private static function plain(MergePaneWidget $pane, int $rows = 14, int $cols = 20): array
    {
        return \array_map(static fn (string $line): string => \rtrim(AnsiUtils::stripAnsiCodes($line)), $pane->render(new RenderContext($cols, $rows)));
    }

    public function testItDrawsItsSideLevelWithTheResult(): void
    {
        $this->assertSame(
            [' a', ' ⇥ HEAD', ' ours', '', '', '', ' b', ' ⇥ HEAD', ' o2', '', '', '', '', ''],
            self::plain($this->shown(ConflictSide::Ours)),
        );
        $this->assertSame(
            [' a', ' ⇤ topic', ' theirs', '', '', '', ' b', ' ⇤ topic', ' t2', '', '', '', '', ''],
            self::plain($this->shown(ConflictSide::Theirs)),
        );
    }

    public function testItStartsFromTheEditorsTopLine(): void
    {
        $this->top = 6;

        $this->assertSame([' b', ' ⇥ HEAD', ' o2'], self::plain($this->shown(ConflictSide::Ours), rows: 3));
    }

    public function testTheRowsLookTheirPart(): void
    {
        $styles = Styles::dark();
        $raw = $this->shown(ConflictSide::Theirs)->render(new RenderContext(20, 8));

        $this->assertStringContainsString($styles->of(Role::Dim)->apply(\mb_str_pad(' a', length: 20)), $raw[0], 'context, dim');
        $this->assertStringContainsString(
            $styles
                ->of(Role::Hunk)
                ->withBold()
                ->apply(\mb_str_pad(' ⇤ topic', length: 20)),
            $raw[1],
            'the label of the conflict the keys act on, bold',
        );
        $this->assertStringContainsString($styles->of(Role::AddedRowBg)->apply(\mb_str_pad(' theirs', length: 20)), $raw[2], 'the side\'s own lines, banded');
        $this->assertStringContainsString($styles->of(Role::Hunk)->apply(\mb_str_pad(' ⇤ topic', length: 20)), $raw[7], 'another conflict\'s label, plain');
    }

    public function testABareMarkerIsLabelledByItsSide(): void
    {
        $pane = $this->pane(ConflictSide::Theirs);
        $pane->show('/p/m.php', "<<<<<<<\no\n=======\nt\n>>>>>>>");

        $this->assertSame(' ⇤ theirs', self::plain($pane, rows: 1)[0]);
    }

    public function testANarrowOrEmptyPaneDrawsWithinItsWidth(): void
    {
        $this->assertSame(['', ''], $this->shown(ConflictSide::Ours)->render(new RenderContext(0, 2)));
        $this->assertSame(['   ', '   '], $this->pane(ConflictSide::Ours)->render(new RenderContext(3, 2)), 'nothing shown yet');
    }

    public function testAClickOnALabelKeepsThatSideAndPutsTheCaretOnIt(): void
    {
        $pane = $this->shown(ConflictSide::Theirs);

        $this->assertFalse($pane->press(7, 3), 'never a drag');

        $this->assertSame("a\n<<<<<<< HEAD\nours\n=======\ntheirs\n>>>>>>> topic\nb\nt2", $this->editorText);
        $this->assertSame(['replace', 'jump:7'], $this->calls);
    }

    public function testAClickOnAScrolledSideFindsTheRowWhereItIsDrawn(): void
    {
        $this->top = 6;
        $pane = $this->shown(ConflictSide::Ours);

        $pane->press(1, 3); // the second conflict's label, drawn second from the top

        $this->assertSame("a\n<<<<<<< HEAD\nours\n=======\ntheirs\n>>>>>>> topic\nb\no2", $this->editorText);
    }

    public function testAClickAnywhereElseDoesNothing(): void
    {
        $pane = $this->shown(ConflictSide::Theirs);
        $pane->press(2, 3);
        $pane->press(40, 3);

        $this->assertSame([], $this->calls);
    }

    public function testKeysKeepThisSideOrBothForTheConflictTheCaretIsOn(): void
    {
        $pane = $this->shown(ConflictSide::Ours);
        $this->caretLine = 6;

        $pane->handleInput('a');
        $this->assertSame("a\n<<<<<<< HEAD\nours\n=======\ntheirs\n>>>>>>> topic\nb\no2", $this->editorText, 'the next conflict below the caret');

        $this->caretLine = 0;
        $pane->handleInput('b');
        $this->assertSame("a\nours\ntheirs\nb\no2", $this->editorText);
    }

    public function testTheResultAsTheEditorHasItIsWhatAKeyResolves(): void
    {
        // Typed into since the pane was last fed: the editor's text is the truth.
        $pane = $this->shown(ConflictSide::Theirs);
        $this->editorText = "x\n".self::RESULT;
        $this->caretLine = 1;

        $pane->handleInput("\r");

        $this->assertStringStartsWith("x\na\ntheirs\nb\n", (string) $this->editorText);
    }

    public function testWithNoTextFromTheEditorTheFedOneIsResolved(): void
    {
        $pane = $this->shown(ConflictSide::Theirs);
        $this->editorText = null;

        $pane->press(1, 3);

        $this->assertSame("a\ntheirs\nb\n<<<<<<< HEAD\no2\n=======\nt2\n>>>>>>> topic", $this->editorText);
    }

    public function testAConflictGoneSinceTheFeedIsLeftAlone(): void
    {
        $pane = $this->shown(ConflictSide::Theirs);
        $this->editorText = 'resolved meanwhile';

        $pane->press(7, 3);

        $this->assertSame([], $this->calls);
    }

    public function testNAndPWalkTheConflictsAndWrap(): void
    {
        $pane = $this->shown(ConflictSide::Ours);

        foreach (['n', 'n', 'n', 'p', 'p'] as $key) {
            $pane->handleInput($key);
        }

        $this->assertSame(['jump:1', 'jump:7', 'jump:1', 'jump:7', 'jump:1'], $this->calls);
    }

    public function testWithNoConflictTheKeysDoNothing(): void
    {
        $pane = $this->pane(ConflictSide::Ours);
        $pane->show('/p/m.php', 'clean');

        foreach (['a', 'b', 'n', 'p', 'x'] as $key) {
            $pane->handleInput($key);
        }

        $this->assertSame([], $this->calls);
    }

    public function testTheTabKeysReachTheTabs(): void
    {
        $pane = $this->shown(ConflictSide::Ours);
        $tabs = new class implements ViewerTabHandler {
            /** @var list<string> */
            public array $calls = [];

            public function cycleBuffer(int $direction): void
            {
                $this->calls[] = 'cycle:'.$direction;
            }

            public function closeBuffer(): void
            {
                $this->calls[] = 'close';
            }

            public function openGotoFile(): void
            {
                $this->calls[] = 'goto';
            }

            public function saveAll(): void
            {
                $this->calls[] = 'save';
            }

            public function viewChanged(): void {}

            public function revealSource(int $line, int $byteCol): void {}
        };
        $pane->setHandler($tabs);

        foreach (["\x1b[1;5C", "\x1b[1;5D", "\x1bw", "\x10", "\x1b\x13"] as $key) {
            $pane->handleInput($key);
        }

        $this->assertSame(['cycle:1', 'cycle:-1', 'close', 'goto', 'save'], $tabs->calls);
    }

    public function testAWheelNotchScrollsTheEditor(): void
    {
        $this->shown(ConflictSide::Ours)->wheelBy(3);

        $this->assertSame(['scroll:3'], $this->calls);
    }

    public function testTheStatusSaysWhichConflictAndTheKeys(): void
    {
        $pane = $this->shown(ConflictSide::Theirs);
        $this->assertSame('Conflict 1 of 2  a keep theirs  b both  n/p next/prev', $pane->statusRight());

        $this->caretLine = 9;
        $this->assertSame('Conflict 2 of 2  a keep theirs  b both  n/p next/prev', $pane->statusRight());

        $pane->show('/p/m.php', 'done');
        $this->assertSame('No conflicts left — save the result', $pane->statusRight());

        $pane->clear();
        $this->assertSame('', $pane->statusRight(), 'nothing shown');
    }

    public function testTheirsIsTheRoutedPaneAndCarriesOursAsItsFlank(): void
    {
        $ours = $this->pane(ConflictSide::Ours);
        $theirs = $this->pane(ConflictSide::Theirs, $ours);

        $this->assertSame('merge', $theirs->paneId());
        $this->assertSame('merge-ours', $ours->paneId());
        $this->assertSame($ours, $theirs->flank());
        $this->assertNull($ours->flank());
    }

    public function testTheFlankHearsWhatThePaneHears(): void
    {
        $ours = $this->pane(ConflictSide::Ours);
        $theirs = $this->pane(ConflictSide::Theirs, $ours);
        $revision = $ours->getRenderRevision();

        $theirs->show('/p/m.php', self::RESULT);
        $this->assertSame(' ⇥ HEAD', self::plain($ours, rows: 2)[1], 'fed the same text');

        $theirs->followSource(7, 0);
        $this->assertGreaterThan($revision + 1, $ours->getRenderRevision(), 'repainted for the caret too');

        $theirs->clear();
        $this->assertSame(['', ''], self::plain($ours, rows: 2), 'and cleared with it');
    }

    public function testAPayloadThatIsNoTextShowsNothing(): void
    {
        $pane = $this->pane(ConflictSide::Ours);
        $pane->show('/p/m.php', null);

        $this->assertSame([''], self::plain($pane, rows: 1));
    }
}
