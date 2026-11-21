<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Process;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/**
 * Outside a repository the runner refuses every call but the ones asked to
 * run anywhere: git's own two-path mode, `diff --no-index`, which is what
 * `porta --diff a b` in a folder git does not track runs.
 */
final class GitProcessRunnerOutsideARepositoryTest extends TestCase
{
    use GitBinary;
    use PreservesPath;
    use PumpsLoop;

    protected function setUp(): void
    {
        $this->skipWithoutGit();
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH);
    }

    public function testTwoFilesAreComparedWithoutARepository(): void
    {
        $folder = new TempProject(['old.txt' => "before\n", 'new.txt' => "after\n"]);

        [$code, $out] = $this->answer(new GitProcessRunner($folder->dir)->runAnywhere(...), ['diff', '--no-color', '--no-index', '--', $folder->path('old.txt'), $folder->path('new.txt')]);

        $this->assertSame(1, $code, 'git diff --no-index exits 1 when the files differ');
        $this->assertStringContainsString("-before\n+after", $out);
    }

    public function testARunThatNeedsTheRepositoryIsRefused(): void
    {
        $folder = new TempProject(['old.txt' => "before\n", 'new.txt' => "after\n"]);

        // The same comparison, asked as a repository's run: refused, whatever its arguments
        $this->assertSame([1, ''], $this->answer(new GitProcessRunner($folder->dir)->runStatus(...), ['diff', '--no-color', '--no-index', '--', $folder->path('old.txt'), $folder->path('new.txt')]));
    }

    public function testWithoutGitEvenAComparisonIsAFailedRun(): void
    {
        $folder = new TempProject(['old.txt' => "before\n", 'new.txt' => "after\n"]);
        \putenv('PATH=/nonexistent');

        $this->assertSame([1, ''], $this->answer(new GitProcessRunner($folder->dir)->runAnywhere(...), ['diff', '--no-index', '--', $folder->path('old.txt'), $folder->path('new.txt')]));
    }

    /**
     * @param \Closure(list<string>, callable(int, string): void): void $run
     * @param list<string>                                             $args
     *
     * @return array{int, string}
     */
    private function answer(\Closure $run, array $args): array
    {
        $answer = null;
        $run($args, static function (int $code, string $out) use (&$answer): void {
            $answer = [$code, $out];
        });
        $this->await(static function () use (&$answer): bool {
            return null !== $answer;
        }, what: 'git to answer');

        return $answer ?? [-1, ''];
    }
}
