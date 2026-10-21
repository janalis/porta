<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests\Merge;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\Merge\MergePaneWidget;
use Porta\Editor\ConflictSide;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
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
    /** A conflict whose ours line is thirty columns long. */
    private const string LONG = "x\n<<<<<<< HEAD\noooooooooooooooooooooooooooooo\n=======\nt\n>>>>>>> topic";

    private const string RESULT = "a\n<<<<<<< HEAD\nours\n=======\ntheirs\n>>>>>>> topic\nb\n<<<<<<< HEAD\no2\n=======\nt2\n>>>>>>> topic";

    /** What the editor holds — the closures read and write it. */
    private ?string $editorText = self::RESULT;

    private int $caretLine = 0;
    private int $top = 0;

    /** Rows of the top line scrolled above the view: soft wrap's, 0 otherwise. */
    private int $segment = 0;

    /** @var array<int, int> rows the editor gives a line, where soft wrap made it more than one */
    private array $editorRows = [];

    /** @var list<int> the lines sticky scroll pins over the view */
    private array $sticky = [];

    /** Whether the editor has a view to give — none with no text file active. */
    private bool $viewed = true;

    /** @var list<string> */
    private array $calls = [];

    private function pane(ConflictSide $side, ?MergePaneWidget $ours = null): MergePaneWidget
    {
        return new MergePaneWidget(
            $side,
            new Translator('en'),
            fn (): ?string => $this->editorText,
            fn (): ?array => $this->viewed ? ['line' => $this->caretLine, 'column' => 0, 'top' => $this->top, 'segment' => $this->segment, 'sticky' => $this->sticky] : null,
            fn (int $line): int => $this->editorRows[$line] ?? 1,
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
            function (): void {
                $this->calls[] = 'save';
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

    public function testEachRowIsTheLineTheEditorShowsThere(): void
    {
        // A sticky-scroll header pinned over the view: the editor's first row
        // is line 0, the next ones lines 7 on — and so are the side's.
        $this->sticky = [0];
        $this->top = 7;

        $this->assertSame([' a', ' ⇥ HEAD', ' o2', '', ''], self::plain($this->shown(ConflictSide::Ours), rows: 5));
    }

    /**
     * Under soft wrap the editor gives a long line several rows: the side
     * gives it as many, so every line below stays level.
     */
    public function testALineTheEditorWrapsTakesAsManyRowsOnTheSide(): void
    {
        $this->editorRows = [0 => 3];

        $this->assertSame([' a', '', '', ' ⇥ HEAD', ' ours'], self::plain($this->shown(ConflictSide::Ours), rows: 5));
    }

    private function long(ConflictSide $side): MergePaneWidget
    {
        $this->editorText = self::LONG;
        $pane = $this->pane($side);
        $pane->show('/p/m.php', self::LONG);

        return $pane;
    }

    /**
     * The side's own text wraps at its own width over the rows it has: padded
     * when it is shorter than the editor's line, cut with … on its last row
     * when it is longer — and a side's band runs on every one.
     */
    public function testASideWrapsItsOwnTextIntoTheRowsTheEditorGives(): void
    {
        // Eleven columns: ten of text after the indent; thirty o's.
        $this->editorRows = [2 => 2];
        $ours = $this->long(ConflictSide::Ours);
        $this->assertSame([' x', ' ⇥ HEAD', ' oooooooooo', ' ooooooooo…'], self::plain($ours, rows: 4, cols: 11), 'more than fits: the last row says so');

        $this->editorRows = [2 => 4];
        $this->assertSame([' oooooooooo', ' oooooooooo', ' oooooooooo', ''], \array_slice(self::plain($ours, rows: 6, cols: 11), offset: 2), 'fewer: blank rows after');

        $theirs = $this->long(ConflictSide::Theirs);
        $raw = $theirs->render(new RenderContext(11, 6));
        $this->assertSame(' t', \rtrim(AnsiUtils::stripAnsiCodes($raw[2])));
        $this->assertSame('', \rtrim(AnsiUtils::stripAnsiCodes($raw[3])));
        $this->assertSame(Styles::dark()->of(Role::AddedRowBg)->apply(AnsiText::blank(11)), $raw[3], 'its band on the rows it does not fill');
    }

    /** Scrolled INTO a tall line, the side starts on the same row of it as the editor. */
    public function testTheTopLineScrolledPartwayStartsAtTheSameRow(): void
    {
        $this->editorRows = [2 => 3];
        $this->top = 2;
        $this->segment = 1;

        // The o's last two rows, then the conflict's filler: from its first row, the third would hold o's too.
        $this->assertSame([' oooooooooo', ' oooooooooo', '', ''], self::plain($this->long(ConflictSide::Ours), rows: 4, cols: 11));
    }

    /** A label the editor's line wraps under keeps to its first row, and only there does a click accept. */
    public function testAWrappedLabelAnswersOnItsFirstRowOnly(): void
    {
        $this->editorRows = [1 => 2];
        $pane = $this->shown(ConflictSide::Theirs);
        $this->assertSame([' a', ' ⇤ topic', '', ' theirs'], self::plain($pane, rows: 4));

        $pane->press(2, 3);
        $this->assertSame(self::RESULT, $this->editorText, 'the row under the label is not the label');
        $pane->press(1, 3);
        $this->assertStringStartsWith("a\ntheirs\nb", (string) $this->editorText);
    }

    /** Pinned headers take one row each, as the editor pins them; the lines below wrap. */
    public function testPinnedHeadersAreOneRowEachAboveWrappedLines(): void
    {
        $this->sticky = [0];
        $this->top = 7;
        $this->editorRows = [0 => 3, 7 => 2];

        $this->assertSame([' a', ' ⇥ HEAD', '', ' o2'], self::plain($this->shown(ConflictSide::Ours), rows: 4));
    }

    public function testASecondClickRightAfterTheFirstFindsTheConflictsAsTheyAreNow(): void
    {
        // Before the feed catches up: the first accept is drawn at once, so
        // the second conflict's label is already where the click lands.
        $pane = $this->shown(ConflictSide::Theirs);

        $pane->press(1, 3);
        $this->assertSame(' ⇤ topic', self::plain($pane, rows: 4)[3], 'the second label moved up');
        $pane->press(3, 3);

        $this->assertSame("a\ntheirs\nb\nt2", $this->editorText);
    }

    public function testNoViewFromTheEditorDrawsNothing(): void
    {
        $pane = $this->shown(ConflictSide::Ours);
        $this->viewed = false;

        $this->assertSame(['', ''], self::plain($pane, rows: 2));
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

    public function testAKeyFindsTheConflictInTheTextAsTypedNotAsLastFed(): void
    {
        // The first conflict resolved by hand, the feed not caught up yet:
        // line 6 is inside the remaining conflict now, between two in the feed.
        $pane = $this->shown(ConflictSide::Ours);
        $this->editorText = "a\ntheirs\nb\n<<<<<<< HEAD\no2\n=======\nt2\n>>>>>>> topic";
        $this->caretLine = 6;

        $pane->handleInput('b');

        $this->assertSame("a\ntheirs\nb\no2\nt2", $this->editorText);
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
        $this->editorText = 'clean';
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

    public function testCtrlSSavesTheResultFromASide(): void
    {
        $this->shown(ConflictSide::Ours)->handleInput("\x13");

        $this->assertSame(['save'], $this->calls);
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
