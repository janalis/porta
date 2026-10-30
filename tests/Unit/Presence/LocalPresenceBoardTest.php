<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Presence;

use PHPUnit\Framework\TestCase;
use Porta\Presence\Beacon;
use Porta\Presence\LocalPresenceBoard;
use Porta\Presence\PresenceBoard;
use Porta\Tests\Support\TempProject;

/** The board in the project's own `.porta/presence/`: one file per instance, answered at once. */
final class LocalPresenceBoardTest extends TestCase
{
    private static function beacon(string $id, string $name = 'Ada'): Beacon
    {
        return new Beacon($id, $name, 'local', 'a.php', 0, 0, -1, 0, 1_700_000_000);
    }

    /** @return list<Beacon>|string */
    private static function read(PresenceBoard $board): array|string
    {
        $read = 'never answered';
        $board->read(static function (array|string $beacons) use (&$read): void {
            $read = $beacons;
        });

        return $read;
    }

    public function testWhatIsPublishedIsReadAndWithdrawnIsGone(): void
    {
        $project = new TempProject();
        $board = new LocalPresenceBoard($project->dir);
        $this->assertSame([], self::read($board), 'no directory yet: nobody here');

        $answers = [];
        $board->publish(self::beacon('aaaaaaaaaaaaaaaa'), static function (?string $error) use (&$answers): void {
            $answers[] = $error;
        });
        $board->publish(self::beacon('bbbbbbbbbbbbbbbb', 'Bo'), static function (?string $error) use (&$answers): void {
            $answers[] = $error;
        });
        $board->publish(self::beacon('aaaaaaaaaaaaaaaa', 'Ada again'), static function (?string $error) use (&$answers): void {
            $answers[] = $error;
        });

        $this->assertSame([null, null, null], $answers);
        $this->assertFileDoesNotExist($project->path(PresenceBoard::DIR.'/aaaaaaaaaaaaaaaa.json.tmp'), 'written beside, then renamed over');
        $project->write(PresenceBoard::DIR.'/notes.txt', 'not a beacon');
        $project->write(PresenceBoard::DIR.'/cccccccccccccccc.json', 'not json either');
        $read = self::read($board);
        $this->assertIsArray($read);
        $this->assertSame(['Ada again', 'Bo'], \array_map(static fn (Beacon $b): string => $b->name, $read), 'one each, the last said; what is not a beacon is skipped');

        $board->withdraw('aaaaaaaaaaaaaaaa');
        $answered = false;
        $board->withdraw('aaaaaaaaaaaaaaaa', static function () use (&$answered): void {
            $answered = true;
        });
        $this->assertTrue($answered, 'answered at once, gone or not');
        $board->close();
        $this->assertSame(['Bo'], \array_map(static fn (Beacon $b): string => $b->name, (array) self::read($board)));
        $this->assertSame(['via' => 'local', 'tick' => 1.0, 'heartbeat' => 5.0], $board->cadence());
    }

    /** `.porta/` comes with a clone: a board that is a link is neither written into nor emptied. */
    public function testABoardThatIsALinkIsNeitherWrittenNorEmptied(): void
    {
        $elsewhere = new TempProject(['aaaaaaaaaaaaaaaa.json' => 'kept']);
        $project = new TempProject(['.porta/keep' => '']);
        \symlink($elsewhere->dir, $project->path(PresenceBoard::DIR));
        $board = new LocalPresenceBoard($project->dir);

        $answer = 'unanswered';
        $board->publish(self::beacon('bbbbbbbbbbbbbbbb'), static function (?string $error) use (&$answer): void {
            $answer = $error;
        });
        $board->withdraw('aaaaaaaaaaaaaaaa');

        $this->assertIsString($answer, 'a board it cannot write');
        $this->assertSame(['aaaaaaaaaaaaaaaa.json'], \array_values(\array_diff((array) \scandir($elsewhere->dir), ['.', '..'])));
    }

    public function testABoardThatCannotBeWrittenOrReadSaysSo(): void
    {
        $project = new TempProject(['.porta' => 'a file where the directory would go']);
        $board = new LocalPresenceBoard($project->dir);

        $error = null;
        $board->publish(self::beacon('aaaaaaaaaaaaaaaa'), static function (?string $answer) use (&$error): void {
            $error = $answer;
        });
        $this->assertSame('cannot write '.$project->dir.'/.porta/presence/aaaaaaaaaaaaaaaa.json', $error);

        $json = self::beacon('aaaaaaaaaaaaaaaa')->toJson();
        $project = new TempProject([PresenceBoard::DIR.'/aaaaaaaaaaaaaaaa.json' => $json]);
        \chmod($project->path(PresenceBoard::DIR), permissions: 0o300);
        try {
            $this->assertSame('cannot read '.$project->dir.'/.porta/presence', self::read(new LocalPresenceBoard($project->dir)));
        } finally {
            \chmod($project->path(PresenceBoard::DIR), permissions: 0o700);
        }
    }

    /** A board directory that is there but read-only is answered the same way — a reason, never a PHP warning. */
    public function testABoardThatIsThereButReadOnlySaysSo(): void
    {
        $project = new TempProject([PresenceBoard::DIR.'/.keep' => '']);
        \chmod($project->path(PresenceBoard::DIR), permissions: 0o500);
        $error = null;
        try {
            new LocalPresenceBoard($project->dir)->publish(self::beacon('aaaaaaaaaaaaaaaa'), static function (?string $answer) use (&$error): void {
                $error = $answer;
            });
        } finally {
            \chmod($project->path(PresenceBoard::DIR), permissions: 0o700);
        }

        $this->assertSame('cannot write '.$project->dir.'/.porta/presence/aaaaaaaaaaaaaaaa.json', $error);
    }

    public function testABeaconWithdrawnBetweenTheListingAndTheReadIsNone(): void
    {
        $project = new TempProject();
        $board = new LocalPresenceBoard($project->dir);
        $board->publish(self::beacon('aaaaaaaaaaaaaaaa'), static function (): void {});
        // A directory where the beacon's file was: listed, but not readable as one.
        \unlink($project->path(PresenceBoard::DIR.'/aaaaaaaaaaaaaaaa.json'));
        \mkdir($project->path(PresenceBoard::DIR.'/aaaaaaaaaaaaaaaa.json'));

        $this->assertSame([], self::read($board));
    }
}
