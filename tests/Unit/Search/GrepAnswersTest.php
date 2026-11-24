<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\GrepAnswers;
use Porta\Tests\Support\PumpsLoop;

/** What grep takes, asked in the background, and the searches that wait on it. */
final class GrepAnswersTest extends TestCase
{
    use PumpsLoop;

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
    }

    /**
     * A question that once went unanswered (here, out of time) holds no search
     * up again: asked again by the next search, it is not waited on — on a
     * slow link every search waited out the timeout.
     */
    public function testAQuestionOnceUnansweredIsNeverWaitedOnAgain(): void
    {
        $answers = new GrepAnswers(\sys_get_temp_dir(), timeout: 0.2);
        $slow = static fn (): array => ['/bin/sh', '-c', 'sleep 2'];
        $first = false;
        $answers->ask('--null', $slow);
        $answers->whenAnswered($this, ['--null'], static function () use (&$first): void {
            $first = true;
        });
        $this->await(static function () use (&$first): bool {
            return $first;
        }, what: 'the first search, once the question timed out');

        $answers->newSearch();
        $answers->ask('--null', $slow);
        $second = false;
        $answers->whenAnswered($this, ['--null'], static function () use (&$second): void {
            $second = true;
        });

        $this->assertTrue($second, 'asked again, but not waited on');
        $this->assertFalse($answers->takes('--null'));
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
