<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\EditHistory;

/**
 * The list of places you were typing. Its whole job is collapsing — typing
 * produces an entry per keystroke, and a list of those is not a shortcut.
 */
final class EditHistoryTest extends TestCase
{
    public function testARUNOfKeystrokesOnOneLineIsOnePlace(): void
    {
        $history = new EditHistory();
        foreach (\range(start: 0, end: 7) as $ignored) {
            $history->record('/a.php', line: 12);
        }

        $this->assertSame(['path' => '/a.php', 'line' => 12], $history->walkBack());
        $this->assertNull($history->walkBack(), 'and there is nothing behind it');
    }

    public function testPressingAgainWalksFurtherBack(): void
    {
        $history = new EditHistory();
        $history->record('/a.php', line: 1);
        $history->record('/a.php', line: 5);
        $history->record('/b.php', line: 2);

        $this->assertSame(['path' => '/b.php', 'line' => 2], $history->walkBack());
        $this->assertSame(['path' => '/a.php', 'line' => 5], $history->walkBack());
        $this->assertSame(['path' => '/a.php', 'line' => 1], $history->walkBack());
        $this->assertNull($history->walkBack(), 'the list is finite and says so');
    }

    public function testAnEditRESTARTSTheWalk(): void
    {
        // You are somewhere new, so the next press means "the place before
        // HERE" — not "one further back than the last press happened to reach".
        $history = new EditHistory();
        $history->record('/a.php', line: 1);
        $history->record('/a.php', line: 5);
        $history->walkBack();
        $history->walkBack(); // walked all the way back

        $history->record('/c.php', line: 9);

        $this->assertSame(['path' => '/c.php', 'line' => 9], $history->walkBack());
        $this->assertSame(['path' => '/a.php', 'line' => 5], $history->walkBack());
    }

    public function testTheSameLineREVISITEDIsANewPlace(): void
    {
        // Only CONSECUTIVE edits at one place collapse. Going away and coming
        // back is two visits, and the list should be able to say so.
        $history = new EditHistory();
        $history->record('/a.php', line: 3);
        $history->record('/a.php', line: 40);
        $history->record('/a.php', line: 3);

        $this->assertSame(['path' => '/a.php', 'line' => 3], $history->walkBack());
        $this->assertSame(['path' => '/a.php', 'line' => 40], $history->walkBack());
        $this->assertSame(['path' => '/a.php', 'line' => 3], $history->walkBack());
    }

    public function testTheSameLineInAnotherFILEIsANewPlace(): void
    {
        $history = new EditHistory();
        $history->record('/a.php', line: 7);
        $history->record('/b.php', line: 7);

        $this->assertSame(['path' => '/b.php', 'line' => 7], $history->walkBack());
        $this->assertSame(['path' => '/a.php', 'line' => 7], $history->walkBack());
    }

    public function testItKeepsTenPlacesAndDropsTheOldest(): void
    {
        // Typing produces entries far faster than navigating does; a walk back
        // through more than a handful has stopped being a shortcut.
        $history = new EditHistory();
        foreach (\range(start: 1, end: 14) as $line) {
            $history->record('/a.php', $line);
        }

        $walked = [];
        while (null !== ($site = $history->walkBack())) {
            $walked[] = $site['line'];
        }

        $this->assertSame([14, 13, 12, 11, 10, 9, 8, 7, 6, 5], $walked);
    }

    public function testARenamedFileIsFOLLOWED(): void
    {
        $history = new EditHistory();
        $history->record('/a.php', line: 4);
        $history->record('/b.php', line: 9);

        $history->rename('/a.php', '/renamed.php');

        $this->assertSame(['path' => '/b.php', 'line' => 9], $history->walkBack());
        $this->assertSame(['path' => '/renamed.php', 'line' => 4], $history->walkBack());
    }

    public function testARenameThatMakesTwoNeighboursTheSamePlaceCollapsesThem(): void
    {
        // Edit A:5, edit B:5, rename B to A — one place, one entry. Without
        // collapsing, a press would appear to do nothing.
        $history = new EditHistory();
        $history->record('/a.php', line: 5);
        $history->record('/b.php', line: 5);
        $history->record('/c.php', line: 1);

        $history->rename('/b.php', '/a.php');

        $this->assertSame(['path' => '/c.php', 'line' => 1], $history->walkBack());
        $this->assertSame(['path' => '/a.php', 'line' => 5], $history->walkBack());
        $this->assertNull($history->walkBack(), 'the pair became one entry');
    }

    public function testARenameOfTheSameLineInADIFFERENTFileKeepsBothEntries(): void
    {
        // Only NEIGHBOURS collapse. A:5, B:9, A:5 stays three places.
        $history = new EditHistory();
        $history->record('/a.php', line: 5);
        $history->record('/b.php', line: 9);
        $history->record('/x.php', line: 5);

        $history->rename('/x.php', '/a.php');

        $this->assertSame(['path' => '/a.php', 'line' => 5], $history->walkBack());
        $this->assertSame(['path' => '/b.php', 'line' => 9], $history->walkBack());
        $this->assertSame(['path' => '/a.php', 'line' => 5], $history->walkBack());
    }

    public function testWithNoEditsAtAllThereIsNowhereToGo(): void
    {
        $this->assertNull(new EditHistory()->walkBack());
    }

    public function testForgetDropsEverySiteInADeletedFile(): void
    {
        $history = new EditHistory();
        $history->record('/p/gone.php', 1);
        $history->record('/p/kept.php', 2);
        $history->record('/p/gone.php', 5);

        $history->forget('/p/gone.php');

        $this->assertSame(['path' => '/p/kept.php', 'line' => 2], $history->walkBack());
        $this->assertNull($history->walkBack(), 'and nothing behind it');
    }

    public function testForgetCollapsesTheNEIGHBOURSItLeavesTouching(): void
    {
        // Removing the middle site puts two entries for the same place next to
        // each other, and one place is one entry — otherwise a press appears to
        // do nothing. rename() collapses for the same reason.
        $history = new EditHistory();
        $history->record('/p/a.php', 4);
        $history->record('/p/gone.php', 1);
        $history->record('/p/a.php', 4);

        $history->forget('/p/gone.php');

        $this->assertSame(['path' => '/p/a.php', 'line' => 4], $history->walkBack());
        $this->assertNull($history->walkBack(), 'one place, one entry');
    }

    public function testForgetRESTARTSAWalkAlreadyInProgress(): void
    {
        // The walk counts back from the end. Removing entries mid-walk leaves
        // that count pointing at a different site than the user last saw, so
        // the next press would skip one.
        $history = new EditHistory();
        $history->record('/p/a.php', 1);
        $history->record('/p/b.php', 2);
        $history->record('/p/gone.php', 3);
        $history->walkBack();
        $history->walkBack();

        $history->forget('/p/gone.php');

        $this->assertSame(['path' => '/p/b.php', 'line' => 2], $history->walkBack(), 'the newest survivor, not wherever the old count landed');
    }

    public function testRenamingADirectoryMovesTheSitesInsideIt(): void
    {
        $history = new EditHistory();
        $history->record('/p/olden/b.php', 1);
        $history->record('/p/old/a.php', 4);

        $history->rename('/p/old', '/p/new');

        $this->assertSame(['path' => '/p/new/a.php', 'line' => 4], $history->walkBack());
        $this->assertSame(['path' => '/p/olden/b.php', 'line' => 1], $history->walkBack(), 'the sibling stayed where it was');
    }
}
