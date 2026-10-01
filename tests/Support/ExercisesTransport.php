<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Assert;
use Porta\Filesystem\RemoteStamp;
use Porta\Filesystem\RemoteTransport;

/**
 * What every transport test does: wait for a verb's answer on the live loop,
 * and put a transport through the structural verbs every server must do the
 * same — shared, so a second transport is held to the first one's answers.
 */
trait ExercisesTransport
{
    use PumpsLoop;

    /**
     * What $start answers through the callback it is handed.
     *
     * @param \Closure(\Closure(mixed): void): void $start
     */
    private function answer(\Closure $start): mixed
    {
        $answer = null;
        $done = false;
        $start(static function (mixed $value) use (&$answer, &$done): void {
            // @mago-expect analysis:mixed-assignment -- the verb's own answer, typed by its caller
            $answer = $value;
            $done = true;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        });

        return $answer;
    }

    /** What a listing or a fetch should say of $relative on $server: its size and modification time now. */
    private function stampOf(TempProject $server, string $relative): RemoteStamp
    {
        \clearstatcache();

        return new RemoteStamp((int) \filesize($server->path($relative)), (int) \filemtime($server->path($relative)));
    }

    /**
     * Over a server holding `README.md` and `src/App.php`: a deep directory
     * made at once, made again without complaint, a directory moved into one
     * that does not exist yet, and directories with what they hold removed.
     */
    private function assertTheStructuralVerbs(RemoteTransport $transport, TempProject $server): void
    {
        Assert::assertNull($this->answer(static fn (\Closure $done) => $transport->mkdir('a/b/c', $done)));
        Assert::assertDirectoryExists($server->path('a/b/c'));
        Assert::assertNull($this->answer(static fn (\Closure $done) => $transport->mkdir('a/b', $done)), 'one already there is fine');

        Assert::assertNull($this->answer(static fn (\Closure $done) => $transport->rename('src', 'lib/src', $done)));
        Assert::assertFileExists($server->path('lib/src/App.php'));

        Assert::assertNull($this->answer(static fn (\Closure $done) => $transport->remove('lib', true, $done)));
        Assert::assertNull($this->answer(static fn (\Closure $done) => $transport->remove('a', true, $done)));
        Assert::assertNull($this->answer(static fn (\Closure $done) => $transport->remove('README.md', false, $done)));
        Assert::assertDirectoryDoesNotExist($server->path('lib'));
        Assert::assertDirectoryDoesNotExist($server->path('a'));
        Assert::assertFileDoesNotExist($server->path('README.md'));
    }
}
