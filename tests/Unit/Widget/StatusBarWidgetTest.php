<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Glyphs;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\StatusMenuSpan;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class StatusBarWidgetTest extends TestCase
{
    /** A status row with one clickable cell spanning cols [0,6) of `left`. */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    private static function provider(bool $menus, string $right = 'R'): callable
    {
        $spans = $menus ? [new StatusMenuSpan(0, 6, static fn (): array => [new MenuItem('Switch', '', static fn () => null)])] : [];

        return static fn (): array => ['left' => "\u{e0a0} main  file.php", 'right' => $right, 'menus' => $spans];
    }

    public function testRendersProviderContentAtExactWidth(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn () => ['left' => 'file.php ●', 'right' => '12:3 LSP:phpactor']);
        $status->beforeRender();

        $line = $status->render(new RenderContext(60, 1))[0];

        $this->assertSame(60, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('file.php', $line);
        $this->assertStringContainsString('phpactor', $line);
    }

    public function testFlashOverridesLeftAndExpires(): void
    {
        $clock = new MockClock('2020-01-01T00:00:00+00:00');
        $status = new StatusBarWidget($clock);
        $status->setProvider(static fn () => ['left' => 'normal', 'right' => 'R']);
        $status->beforeRender();

        $status->flash('Saved!', 0.05);
        $line = $status->render(new RenderContext(40, 1))[0];
        $this->assertStringContainsString('Saved!', $line);

        $clock->sleep(0.08); // advance past the 0.05s flash window, deterministically
        $status->beforeRender();
        $line = $status->render(new RenderContext(40, 1))[0];
        $this->assertStringNotContainsString('Saved!', $line);
        $this->assertStringContainsString('normal', $line);
    }

    public function testAFlashOutranksTheRightSideLegend(): void
    {
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(static fn () => ['left' => 'file.php', 'right' => '1:1  LSP:none  Ctrl+S save  Ctrl+T panel  Ctrl+Q quit']);
        $status->beforeRender();

        $status->flash('Saved a/really/long/path/src/Demo.php', 1.0);
        $line = $status->render(new RenderContext(50, 1))[0];
        $this->assertSame(50, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('Saved a/really/long/path/src/Demo.php', $line, 'the flash renders whole — the legend yields');
        $this->assertStringContainsString('…', AnsiUtils::stripAnsiCodes($line), 'the right side truncates with a mark');
    }

    public function testLongLeftContentTruncates(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn () => ['left' => str_repeat('x', times: 200), 'right' => 'RIGHT']);
        $status->beforeRender();

        $line = $status->render(new RenderContext(50, 1))[0];
        $this->assertSame(50, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('RIGHT', $line, 'right side survives truncation');
    }

    /**
     * The right side is the pane's own facts plus the key legend, and it can be
     * wider than the terminal on its own — the graph pane's is 78 columns, so a
     * vertical split at 70 is enough. It used to reach the end of render() over
     * width and be chopped there by the whole-row truncate, which passes an
     * EMPTY ellipsis because by then the row is meant to be exactly the width
     * already: the legend came out cut mid-word with nothing to say it was cut.
     */
    public function testAnOverWideRightSideIsCutLegiblyRatherThanChoppedOffTheEnd(): void
    {
        $legend = '3 nodes · 2 edges  Fit (22%)  +/- zoom  0 fit  n/p select  / find  Ctrl+Q quit';
        $status = new StatusBarWidget();
        $status->setProvider(static fn (): array => ['left' => 'g.dot', 'right' => $legend]);
        $status->beforeRender();

        $line = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(70, 1))[0]);

        $this->assertSame(70, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString(Glyphs::ELLIPSIS, $line, 'the cut says it is one');
        $this->assertStringNotContainsString('Ctrl+Q quit', $line, 'and the part that did not fit is gone, not half-drawn');
    }

    public function testWithoutProviderRendersEmptyBar(): void
    {
        $status = new StatusBarWidget();
        $status->beforeRender();

        $line = $status->render(new RenderContext(20, 1))[0];
        $this->assertSame(20, AnsiUtils::visibleWidth($line));
    }

    public function testStatusCellMenuTracksTheBranchCellWithTheLeadingSpace(): void
    {
        $status = new StatusBarWidget();
        // The cell occupies cols [0,6) in `left`; the row's one leading space shifts it to [1,7).
        $status->setProvider(self::provider(menus: true));
        $status->beforeRender();
        $status->render(new RenderContext(60, 1));

        $this->assertNull($status->menuAt(0), 'the leading space is not part of the cell');
        $this->assertNotNull($status->menuAt(1), 'the on-screen span accounts for it');
        $this->assertNotNull($status->menuAt(6), 'the last column of the cell');
        $this->assertNull($status->menuAt(7), 'and the column past it');
        $this->assertSame(['Switch'], array_map(static fn (MenuItem $i): string => $i->label, $status->menuAt(1)()), 'the cell carries its OWN menu');
    }

    public function testStatusCellMenuIsNullWithoutABranch(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(self::provider(menus: false));
        $status->beforeRender();
        $status->render(new RenderContext(60, 1));

        $this->assertNull($status->menuAt(1));
    }

    public function testStatusCellMenuIsNullWhileFlashing(): void
    {
        $status = new StatusBarWidget(new MockClock('2020-01-01T00:00:00+00:00'));
        $status->setProvider(self::provider(menus: true));
        $status->beforeRender();
        // A flash LONGER than the cell, deliberately: a short one is excluded
        // by the truncation filter anyway, so this would pass with the flash
        // guard deleted and prove nothing about it.
        $status->flash(str_repeat('Saved ', times: 4), 1.0);
        $status->render(new RenderContext(60, 1));

        $this->assertNull($status->menuAt(1), 'a flash replaces the prefix, so there is no hit region');
    }

    public function testFlashSetsAFutureExpiryNotAPastOne(): void
    {
        $clock = new MockClock('2020-01-01T00:00:00+00:00');
        $status = new StatusBarWidget($clock);
        $status->setProvider(static fn () => ['left' => 'normal', 'right' => 'R']);
        $status->beforeRender();

        $status->flash('Saved!', 10.0);
        $status->beforeRender(); // a second frame, no time elapsed: must not already be expired

        $line = $status->render(new RenderContext(40, 1))[0];
        $this->assertStringContainsString('Saved!', $line, 'a flash set 10s in the future must not expire on the very next frame');
    }

    public function testFlashInvalidatesImmediately(): void
    {
        $status = new StatusBarWidget();
        $before = $status->getRenderRevision();

        $status->flash('Saved!');

        $this->assertGreaterThan($before, $status->getRenderRevision());
    }

    public function testFlashDoesNotExpireAtTheExactExpiryInstant(): void
    {
        $clock = new MockClock('2020-01-01T00:00:00+00:00');
        $status = new StatusBarWidget($clock);
        $status->setProvider(static fn () => ['left' => 'normal', 'right' => 'R']);
        $status->beforeRender();

        $status->flash('Saved!', 0.0); // until == now, exactly
        $status->beforeRender(); // no time elapsed → now == until still

        $line = $status->render(new RenderContext(40, 1))[0];
        $this->assertStringContainsString('Saved!', $line, 'now == until is not yet past the expiry (strictly greater-than)');
    }

    public function testFlashExpiryInvalidates(): void
    {
        $clock = new MockClock('2020-01-01T00:00:00+00:00');
        $status = new StatusBarWidget($clock);
        $status->setProvider(static fn () => ['left' => 'normal', 'right' => 'R']);
        $status->beforeRender();
        $status->flash('Saved!', 0.01);
        $clock->sleep(0.02);

        $before = $status->getRenderRevision();
        $status->beforeRender();

        $this->assertGreaterThan($before, $status->getRenderRevision(), 'expiry must invalidate so the bar repaints without the flash');
    }

    public function testOnlyTheRightChangingStillInvalidates(): void
    {
        $status = new StatusBarWidget();
        $right = 'A';
        $status->setProvider(static function () use (&$right): array {
            return ['left' => 'same', 'right' => $right];
        });
        $status->beforeRender();
        $before = $status->getRenderRevision();

        $right = 'B'; // left unchanged, right changed
        $status->beforeRender();

        $this->assertGreaterThan($before, $status->getRenderRevision(), 'a right-only change must still invalidate');
    }

    public function testOnlyTheLeftChangingStillInvalidates(): void
    {
        $status = new StatusBarWidget();
        $left = 'A';
        $status->setProvider(static function () use (&$left): array {
            return ['left' => $left, 'right' => 'same'];
        });
        $status->beforeRender();
        $before = $status->getRenderRevision();

        $left = 'B'; // right unchanged, left changed
        $status->beforeRender();

        $this->assertGreaterThan($before, $status->getRenderRevision(), 'a left-only change must still invalidate');
    }

    public function testAFlashLongerThanTheAvailableWidthLeavesNoRoomForTheRightSide(): void
    {
        // The flash text alone reaches the min(...) cap, so rightMax's final
        // "- 3" must hold exactly: one column too many and the ellipsis-only
        // right side gains an extra character.
        $clock = new MockClock('2020-01-01T00:00:00+00:00');
        $status = new StatusBarWidget($clock);
        $status->setProvider(static fn () => ['left' => 'x', 'right' => str_repeat('R', times: 30)]);
        $status->beforeRender();
        $status->flash(str_repeat('F', times: 25), 5.0);

        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(20, 1))[0]);
        $this->assertStringEndsWith('  ', $plain, 'the right side is fully squeezed out (rightMax = 0), leaving two trailing spaces');
    }

    public function testAShortFlashLeavesExactlyTheComputedWidthForTheRightSide(): void
    {
        $clock = new MockClock('2020-01-01T00:00:00+00:00');
        $status = new StatusBarWidget($clock);
        $status->setProvider(static fn () => ['left' => 'x', 'right' => str_repeat('R', times: 30)]);
        $status->beforeRender();
        $status->flash('SAVED', 5.0); // visibleWidth 5, well under the columns-3 cap

        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(20, 1))[0]);
        // rightMax = max(0, 20 - 5 - 3) = 12 → 11 'R's + the ellipsis.
        $this->assertStringContainsString(str_repeat('R', times: 11).'…', $plain);
        $this->assertStringNotContainsString(str_repeat('R', times: 12), $plain, 'one column more than rightMax must not fit');
    }

    public function testLeftMaxLeavesExactlyThreeColumnsForThePadAndRightMargin(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn () => ['left' => str_repeat('L', times: 30), 'right' => 'RR']);
        $status->beforeRender();

        // leftMax = max(0, 20 - 2 - 3) = 15, spent from both ends now: 7 + the
        // ellipsis + 7. The arithmetic is what this pins; the shape it is spent
        // in belongs to the elision test below.
        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(20, 1))[0]);
        $this->assertStringContainsString(str_repeat('L', times: 7).'…'.str_repeat('L', times: 7), $plain);
        $this->assertStringNotContainsString(str_repeat('L', times: 8).'…', $plain);
    }

    /**
     * The file being edited outranks a legend of keys.
     *
     * The right side used to be handed `columns - 3` and the left whatever was
     * left, so the LEFT was the first thing sacrificed and a static cheat
     * sheet the last. At 100 columns the file name was gone in English; in
     * German, where the LSP hint is half again as long, it was gone at 140 —
     * an ordinary terminal, showing which keys exist instead of which file you
     * are in.
     */
    public function testTheFileNameOutlivesTheKeyLegend(): void
    {
        $status = new StatusBarWidget();
        $legend = '1:1  LSP:keiner (einen PHP-Server in den Einstellungen aktivieren)  Ctrl+S speichern  Ctrl+T Panel  Ctrl+Space vervollständigen  Ctrl+Q beenden';
        $status->setProvider(static fn (): array => ['left' => 'src/Some/Deep/Namespace/ExampleClass.php', 'right' => $legend]);
        $status->beforeRender();

        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(140, 1))[0]);

        // Not the whole path — the floor is 24 columns — but the end of it,
        // which is the half that says WHICH file. Before, none of it was here.
        $this->assertStringContainsString('pleClass.php', $plain, 'the file survives');
        $this->assertStringContainsString('1:1', $plain, 'and so do the facts beside it');
        $this->assertStringNotContainsString('Ctrl+Q beenden', $plain, 'the legend tail is what yielded');
        $this->assertSame(140, AnsiUtils::visibleWidth($plain));
    }

    /**
     * A path cut from the right names nothing: `src/Some/Deep/…` is every file
     * in the directory. The budget is spent on both ends instead, so the one
     * word that identifies the buffer survives the squeeze.
     */
    public function testASqueezedPathKeepsItsFileNameNotJustItsPrefix(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn (): array => ['left' => 'src/Some/Deep/Namespace/ExampleClass.php', 'right' => str_repeat('R', times: 60)]);
        $status->beforeRender();

        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(90, 1))[0]);

        $this->assertStringContainsString('Class.php', $plain);
        $this->assertStringContainsString('src/', $plain, 'and enough of the head to place it');
    }

    /**
     * The floor is a floor, not a claim: on a narrow split, spelling the path
     * in full at the cost of the caret position trades one missing fact for
     * another, so it never takes more than half the row.
     */
    public function testTheLeftFloorNeverTakesMoreThanHalfTheRow(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn (): array => ['left' => str_repeat('L', times: 40), 'right' => str_repeat('R', times: 40)]);
        $status->beforeRender();

        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(30, 1))[0]);

        $this->assertSame(30, AnsiUtils::visibleWidth($plain));
        $this->assertGreaterThanOrEqual(10, substr_count($plain, needle: 'R'), 'the right keeps its half');
    }

    /**
     * A span is an offset into the UNCUT left, and a middle cut moves every
     * column after it — while leaving the width the old filter compared still
     * plausible, which is the shape of a hit region that answers for the wrong
     * cell. None are offered once anything was elided.
     */
    public function testNoCellIsClickableOnceTheLeftWasElided(): void
    {
        $status = new StatusBarWidget();
        $span = new StatusMenuSpan(0, 6, static fn (): array => [new MenuItem('Switch', '', static fn () => null)]);
        $status->setProvider(static fn (): array => ['left' => "\u{e0a0} main  src/Some/Deep/Namespace/ExampleClass.php", 'right' => str_repeat('R', times: 40), 'menus' => [$span]]);
        $status->beforeRender();
        $status->render(new RenderContext(60, 1));

        $this->assertNull($status->menuAt(1), 'the branch cell is not offered on a row it can no longer place');
    }

    public function testMenuSpanEndingExactlyAtTheLeftWidthBoundaryStillHitTests(): void
    {
        $status = new StatusBarWidget();
        // 'abcde' is 5 columns; a span ending exactly at column 5 (the boundary,
        // not past it) must still count as fully on-screen.
        $span = new StatusMenuSpan(0, 5, static fn (): array => [new MenuItem('Item', '', static fn () => null)]);
        $status->setProvider(static fn () => ['left' => 'abcde', 'right' => 'R', 'menus' => [$span]]);
        $status->beforeRender();
        $status->render(new RenderContext(40, 1));

        $this->assertNotNull($status->menuAt(1), 'a span ending exactly at leftWidth is still fully visible');
    }

    public function testGapFillsExactlyToTheRemainingWidth(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn () => ['left' => str_repeat('L', times: 5), 'right' => str_repeat('R', times: 5)]);
        $status->beforeRender();

        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(20, 1))[0]);
        // 1 (pad) + 5 (left) + gap + 5 (right) + 1 (pad) = 20 → gap = 8.
        $this->assertSame(' '.str_repeat('L', times: 5).str_repeat(' ', times: 8).str_repeat('R', times: 5).' ', $plain);
    }

    public function testTheRowIsAssembledAsPadLeftGapRightPadInThatExactOrder(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn () => ['left' => 'AAA', 'right' => 'BBB']);
        $status->beforeRender();

        $plain = AnsiUtils::stripAnsiCodes($status->render(new RenderContext(12, 1))[0]);
        $this->assertSame(' AAA    BBB ', $plain, 'exactly: leading pad, left, the gap, right, trailing pad');
    }

    /**
     * Taking a flash back redraws, and only takes back the one it was given.
     *
     * The withdrawal exists for a message about something IN PROGRESS — a
     * drag's "Moving 3 files" is not true once the drag ends, and sitting out
     * its remaining seconds covers whatever the drop has to say. Clearing
     * without redrawing leaves the stale line on screen until something else
     * happens to invalidate, which is exactly the case nobody is watching.
     */
    public function testWithdrawingAFlashClearsItAndRedraws(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn (): array => ['left' => 'idle', 'right' => '']);
        $status->flash('Moving 3 files');
        $status->beforeRender();
        $this->assertStringContainsString('Moving 3 files', AnsiUtils::stripAnsiCodes($status->render(new RenderContext(40, 1))[0]));
        $before = $status->getRenderRevision();

        $status->withdrawFlash('Moving 3 files');

        $this->assertNotSame($before, $status->getRenderRevision(), 'the strip was marked for redraw');
        $status->beforeRender();
        $this->assertStringNotContainsString('Moving 3 files', AnsiUtils::stripAnsiCodes($status->render(new RenderContext(40, 1))[0]));
    }

    /** A message posted since is left alone — the withdrawal names its own. */
    public function testWithdrawingSomethingElseLeavesTheFlashStanding(): void
    {
        $status = new StatusBarWidget();
        $status->setProvider(static fn (): array => ['left' => 'idle', 'right' => '']);
        $status->flash('Saved');

        $status->withdrawFlash('Moving 3 files');

        $status->beforeRender();
        $this->assertStringContainsString('Saved', AnsiUtils::stripAnsiCodes($status->render(new RenderContext(40, 1))[0]));
    }

    public function testStatusCellMenuIsNullWhenTruncatedOff(): void
    {
        $status = new StatusBarWidget();
        // A wide right side + narrow terminal truncates the branch off the left.
        $status->setProvider(self::provider(menus: true, right: str_repeat('R', times: 40)));
        $status->beforeRender();
        $status->render(new RenderContext(20, 1));

        $this->assertNull($status->menuAt(1), 'a clipped cell offers no hit region');
    }
}
