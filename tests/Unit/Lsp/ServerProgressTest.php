<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Lsp\ServerProgress;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * `$/progress` is how a server says what it is doing. Porta declared no
 * `window.workDoneProgress` and registered no handler, so this is the first
 * time "Indexing" reaches the screen.
 *
 * The bound matters as much as the label: this map lives for the process, on a
 * single event loop, and is filled by tokens the SERVER invents.
 */
final class ServerProgressTest extends TestCase
{
    private const string FIRST = 't1';
    private const string SECOND = 't2';
    private const string ABSENT = 'ghost';

    /** The spec allows an integer token; this is one. */
    private const int NUMERIC = 7;

    /** The last token the bound still tracks, given MAX_TOKENS of 32. */
    private const string LAST_TRACKED = 't31';

    private function progress(): ServerProgress
    {
        return new ServerProgress(new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20)));
    }

    private static function begin(string $key, string $title, ?int $percentage = null): array
    {
        $value = ['kind' => 'begin', 'title' => $title];
        if (null !== $percentage) {
            $value['percentage'] = $percentage;
        }

        return ['token' => $key, 'value' => $value];
    }

    public function testIdleUntilAServerSaysOtherwise(): void
    {
        $progress = $this->progress();

        $this->assertNull($progress->label());
        $this->assertFalse($progress->isBusy());
    }

    public function testABegunTaskIsReportedByTitle(): void
    {
        $progress = $this->progress();

        $progress->handle(self::begin(self::FIRST, 'Indexing'));

        $this->assertSame('Indexing', $progress->label());
        $this->assertTrue($progress->isBusy());
    }

    public function testAPercentageIsAppendedToTheTitle(): void
    {
        $progress = $this->progress();

        $progress->handle(self::begin(self::FIRST, 'Indexing'));
        $progress->handle(['token' => self::FIRST, 'value' => ['kind' => 'report', 'percentage' => 40]]);

        $this->assertSame('Indexing 40%', $progress->label());
    }

    public function testAReportKeepsFieldsItDoesNotMention(): void
    {
        // A report carries only what changed; blanking the title mid-task would
        // leave a bare percentage with no idea what it measures.
        $progress = $this->progress();

        $progress->handle(self::begin(self::FIRST, 'Indexing', 10));
        $progress->handle(['token' => self::FIRST, 'value' => ['kind' => 'report', 'message' => 'src/']]);

        $this->assertSame('Indexing: src/ 10%', $progress->label());
    }

    public function testEndClearsTheTaskCompletely(): void
    {
        $progress = $this->progress();

        $progress->handle(self::begin(self::FIRST, 'Indexing'));
        $progress->handle(['token' => self::FIRST, 'value' => ['kind' => 'end']]);

        $this->assertNull($progress->label());
        $this->assertFalse($progress->isBusy());
    }

    public function testTheNEWESTTaskIsTheOneShown(): void
    {
        // One slot on the status bar; the newest task is the one the user just
        // caused.
        $progress = $this->progress();

        $progress->handle(self::begin(self::FIRST, 'Indexing'));
        $progress->handle(self::begin(self::SECOND, 'Resolving'));

        $this->assertSame('Resolving', $progress->label());
    }

    public function testEndingOneOfTwoTasksFallsBackToTheOther(): void
    {
        $progress = $this->progress();

        $progress->handle(self::begin(self::FIRST, 'Indexing'));
        $progress->handle(self::begin(self::SECOND, 'Resolving'));
        $progress->handle(['token' => self::SECOND, 'value' => ['kind' => 'end']]);

        $this->assertSame('Indexing', $progress->label());
    }

    public function testAnIntegerTokenIsAcceptedAndEndsUnderTheSameKey(): void
    {
        $progress = $this->progress();

        $progress->handle(['token' => self::NUMERIC, 'value' => ['kind' => 'begin', 'title' => 'Indexing']]);
        $this->assertTrue($progress->isBusy());

        $progress->handle(['token' => self::NUMERIC, 'value' => ['kind' => 'end']]);
        $this->assertFalse($progress->isBusy());
    }

    public function testAReportForWorkThatNeverBeganIsIgnored(): void
    {
        // Otherwise a stray report would invent a task with an empty title that
        // nothing will ever end.
        $progress = $this->progress();

        $progress->handle(['token' => self::ABSENT, 'value' => ['kind' => 'report', 'percentage' => 50]]);

        $this->assertFalse($progress->isBusy());
    }

    public function testTheMapIsBoundedSoAServerCannotGrowItForever(): void
    {
        // A server that begins work it never ends must not leak for the life of
        // the process.
        $progress = $this->progress();
        for ($i = 0; $i < 200; ++$i) {
            $progress->handle(self::begin('t'.$i, 'Task '.$i));
        }

        // The 33rd onwards were refused, so the newest TRACKED task still shows.
        $this->assertSame('Task 31', $progress->label());
    }

    public function testTasksAlreadyTrackedStillUpdateOnceTheBoundIsReached(): void
    {
        $progress = $this->progress();
        for ($i = 0; $i < 200; ++$i) {
            $progress->handle(self::begin('t'.$i, 'Task '.$i));
        }

        $progress->handle(['token' => self::LAST_TRACKED, 'value' => ['kind' => 'report', 'percentage' => 90]]);

        $this->assertSame('Task 31 90%', $progress->label());
    }

    public function testAMalformedNotificationIsIgnored(): void
    {
        $progress = $this->progress();

        $progress->handle([]);
        $progress->handle(['token' => ['not', 'scalar'], 'value' => ['kind' => 'begin']]);
        $progress->handle(['token' => self::FIRST]);
        $progress->handle(['token' => self::FIRST, 'value' => ['kind' => 'unknown']]);

        $this->assertFalse($progress->isBusy());
    }
}
