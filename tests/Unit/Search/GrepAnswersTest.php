<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\GrepAnswers;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;

/** What grep takes, asked in the background, and the searches that wait on it. */
final class GrepAnswersTest extends TestCase
{
    use PumpsLoop;

    /** Every probe $answers started has ended: none is left to fire in a later test. */
    private function drained(GrepAnswers $answers): void
    {
        $this->await(static fn (): bool => !\in_array(true, (array) Probe::get($answers, property: 'asking'), strict: true), what: 'the probes to end');
    }

    /** A probe that answers $code. */
    private static function answering(int $code): \Closure
    {
        return static fn (): array => ['/bin/sh', '-c', 'exit '.$code];
    }

    public function testASearchWaitsForItsAnswerAndRunsWhenItLands(): void
    {
        $answers = new GrepAnswers(\sys_get_temp_dir());
        $ran = false;

        $answers->ask('--null', self::answering(1));
        $answers->whenAnswered($this, ['--null'], static function () use (&$ran): void {
            $ran = true;
        });
        $this->assertFalse($ran, 'parked while the answer is out');

        $this->await(static function () use (&$ran): bool {
            return $ran;
        }, what: 'the search to run on the answer');
        $this->assertTrue($answers->takes('--null'));
    }

    /** It waits only on what it needs: a -P question out holds no search that asks nothing of it. */
    public function testASearchWaitsOnlyOnTheFlagsItNeeds(): void
    {
        $answers = new GrepAnswers(\sys_get_temp_dir());
        $answers->ask('-P', static fn (): array => ['/bin/sh', '-c', 'sleep 1; exit 1']);
        $ran = false;

        $answers->whenAnswered($this, ['--null'], static function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
        $this->drained($answers);
    }

    /**
     * A question that once went unanswered (an exit that is no grep's answer:
     * ssh's 255, a kill, a timeout) holds no search up again: asked again by
     * the next search, it is not waited on — on a slow link every search
     * waited out the timeout.
     */
    public function testAQuestionOnceUnansweredIsNeverWaitedOnAgain(): void
    {
        $answers = new GrepAnswers(\sys_get_temp_dir());
        $first = false;
        $answers->ask('--null', self::answering(3));
        $answers->whenAnswered($this, ['--null'], static function () use (&$first): void {
            $first = true;
        });
        $this->await(static function () use (&$first): bool {
            return $first;
        }, what: 'the first search, once the question went unanswered');

        $answers->newSearch();
        $answers->ask('--null', static fn (): array => ['/bin/sh', '-c', 'sleep 0.5; exit 1']);
        $second = false;
        $answers->whenAnswered($this, ['--null'], static function () use (&$second): void {
            $second = true;
        });

        $this->assertTrue($second, 'asked again, but not waited on');
        $this->drained($answers);
        $this->assertTrue($answers->takes('--null'), 'and its answer, when it lands, is kept');
    }

    /** A search that drops another engine's, run as the answer lands, keeps it from running: the waiters are read live. */
    public function testASearchDroppedByOneRunBeforeItIsNotRun(): void
    {
        $answers = new GrepAnswers(\sys_get_temp_dir());
        $one = new \stdClass();
        $two = new \stdClass();
        $ran = [];
        $answers->ask('--null', self::answering(1));
        $answers->whenAnswered($one, ['--null'], static function () use ($answers, $two, &$ran): void {
            $ran[] = 'one';
            $answers->drop($two);
        });
        $answers->whenAnswered($two, ['--null'], static function () use (&$ran): void {
            $ran[] = 'two';
        });

        $this->drained($answers);

        $this->assertSame(['one'], $ran);
    }

    /** No one to ask yet (a server not reached): nothing is asked, and no search waits on it. */
    public function testWithNoOneToAskNothingIsAskedOrWaitedOn(): void
    {
        $answers = new GrepAnswers(\sys_get_temp_dir());
        $ran = false;

        $answers->ask('--null', static fn (): ?array => null);
        $answers->whenAnswered($this, ['--null'], static function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
        $this->assertFalse($answers->answered('--null'));
    }

    /** One instance serves every engine of a project: each is told when its answer lands, and asked once. */
    public function testEnginesSharingTheAnswersAreAskedOnceAndEachRuns(): void
    {
        $answers = new GrepAnswers(\sys_get_temp_dir());
        $asked = 0;
        $probe = static function () use (&$asked): array {
            ++$asked;

            return ['/bin/sh', '-c', 'exit 1'];
        };
        $one = new \stdClass();
        $two = new \stdClass();
        $ran = [];

        foreach (['one' => $one, 'two' => $two] as $name => $engine) {
            $answers->ask('--null', $probe);
            $answers->whenAnswered($engine, ['--null'], static function () use (&$ran, $name): void {
                $ran[] = $name;
            });
        }
        $answers->drop($two);
        $this->await(static function () use (&$ran): bool {
            return [] !== $ran;
        }, what: 'the answer');
        $this->pump(0.1);

        $this->assertSame(1, $asked);
        $this->assertSame(['one'], $ran, 'the dropped one does not run');
    }
}
