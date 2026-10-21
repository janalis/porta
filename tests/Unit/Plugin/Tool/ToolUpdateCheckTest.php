<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Plugin\Tool\ToolUpdateCheck;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The throttle on the background "there is a newer version" check.
 *
 * Every test drives an explicit $now, so nothing here depends on the wall
 * clock — a test that waited out a real day would be no test at all.
 */
final class ToolUpdateCheckTest extends TestCase
{
    private const int NOON = 1_800_000_000;

    private function check(TempProject $home): ToolUpdateCheck
    {
        return new ToolUpdateCheck(new Filesystem(), $home->path('.porta'));
    }

    /**
     * The note is a MACHINE-global fact ("this machine looked today"), so it
     * sits in ~/.porta, not in `.porta.json` — a project file travels to
     * everyone who clones the repository.
     */
    public function testTheNoteSitsInThePortaDirectory(): void
    {
        $home = new TempProject();

        $this->check($home)->record(self::NOON, ['symfony-lsp/symfony-lsp' => '0.21.0']);

        $this->assertSame(
            ['checkedAt' => self::NOON, 'latest' => ['symfony-lsp/symfony-lsp' => '0.21.0']],
            \json_decode((string) \file_get_contents($home->path('.porta/tool-updates.json')), associative: true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    /** A tool this round could not ask about keeps what the last round saw. */
    public function testARecordMergesOverWhatWasSeenBefore(): void
    {
        $home = new TempProject();
        $check = $this->check($home);

        $check->record(self::NOON, ['p/a' => '1.0.0', 'p/b' => '2.0.0']);
        $check->record(self::NOON + 1, ['p/b' => '2.1.0']);

        $this->assertSame(['p/a' => '1.0.0', 'p/b' => '2.1.0'], $check->latest());
    }

    public function testUnusableLatestEntriesAreDropped(): void
    {
        $home = new TempProject();
        \mkdir($home->path('.porta'), permissions: 0o755, recursive: true);
        \file_put_contents($home->path('.porta/tool-updates.json'), data: '{"latest": {"p/a": "1.0.0", "p/b": 2, "0": "x"}}');

        $this->assertSame(['p/a' => '1.0.0'], $this->check($home)->latest());
        \file_put_contents($home->path('.porta/tool-updates.json'), data: '{"latest": "1.0.0"}');
        $this->assertSame([], $this->check($home)->latest());
    }

    /** Never looked is due, and costs exactly one request to settle. */
    public function testAMachineThatNeverLookedIsDue(): void
    {
        $home = new TempProject();

        $this->assertTrue($this->check($home)->due(self::NOON, ToolUpdateCheck::DAY));
    }

    public function testACheckJustMadeIsNotDueAgain(): void
    {
        $home = new TempProject();
        $check = $this->check($home);

        $check->record(self::NOON, []);

        $this->assertFalse($check->due(self::NOON, ToolUpdateCheck::DAY), 'the same second');
        $this->assertFalse($check->due(self::NOON + ToolUpdateCheck::DAY - 1, ToolUpdateCheck::DAY), 'one second short of a day');
        $this->assertTrue($check->due(self::NOON + ToolUpdateCheck::DAY - 1, 60), 'a shorter cadence is due sooner');
    }

    public function testADayLaterIsDue(): void
    {
        $home = new TempProject();
        $check = $this->check($home);

        $check->record(self::NOON, []);

        $this->assertTrue($check->due(self::NOON + ToolUpdateCheck::DAY, ToolUpdateCheck::DAY));
    }

    /**
     * A clock that was wrong when the note was written would otherwise switch
     * the check off until it caught up — which, for a badly set clock, is
     * never.
     */
    public function testATimestampFromTheFutureIsDue(): void
    {
        $home = new TempProject();
        $check = $this->check($home);

        $check->record(self::NOON + 86_400_000, []);

        $this->assertTrue($check->due(self::NOON, ToolUpdateCheck::DAY));
    }

    /**
     * Unreadable state means "never checked", which costs one request — the
     * safe direction. Trusting a corrupt timestamp would switch the feature
     * off silently and permanently.
     *
     * @return iterable<string, array{string}>
     */
    public static function unusableNotes(): iterable
    {
        yield 'not json at all' => ['not json'];
        yield 'json, but not an object' => ['42'];
        yield 'an object without the key' => ['{"latest":{}}'];
        yield 'a timestamp that is not a number' => ['{"checkedAt":"yesterday"}'];
        yield 'a float, which is not a second count we wrote' => ['{"checkedAt":1.5}'];
    }

    #[DataProvider('unusableNotes')]
    public function testAnUnusableNoteIsDue(string $contents): void
    {
        $home = new TempProject();
        \mkdir($home->path('.porta'), permissions: 0o755, recursive: true);
        \file_put_contents($home->path('.porta/symfony-lsp-check.json'), $contents);

        $this->assertTrue($this->check($home)->due(self::NOON, ToolUpdateCheck::DAY));
    }

    /**
     * A machine that cannot write the note checks again next launch: one
     * wasted request, not a broken feature — and never a crash on a read-only
     * home.
     */
    public function testAnUnwritableHomeDoesNotThrow(): void
    {
        $home = new TempProject();
        \chmod($home->dir, permissions: 0o555);

        try {
            new ToolUpdateCheck(new Filesystem(), $home->path('nope'))->record(self::NOON, []);
            $this->assertTrue(new ToolUpdateCheck(new Filesystem(), $home->path('nope'))->due(self::NOON, ToolUpdateCheck::DAY), 'nothing was recorded, so it is still due');
        } finally {
            \chmod($home->dir, permissions: 0o755);
        }
    }

    /** A machine with no home keeps no note: nothing is written, nothing is read back. */
    public function testWithNoHomeNothingIsKept(): void
    {
        $check = new ToolUpdateCheck(new Filesystem(), null);
        $check->record(self::NOON, ['p/x' => '2.0.0']);

        $this->assertSame([], $check->latest());
        $this->assertFalse($check->due(self::NOON, ToolUpdateCheck::DAY), 'and it is never due');
    }
}
