<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Assert;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;
use Porta\Ui\Glyphs;

/**
 * A real transport against a real server that goes away and comes back: what
 * is saved meanwhile waits, and lands once it is back — the same for every
 * kind of server, so each one's test says it once.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait ProvesTheLinkOverAServer
{
    use PumpsLoop;

    abstract private function server(): DummyServer;

    abstract private function remote(): RemoteTransport;

    abstract private function spec(): RemoteSpec;

    public function testASaveWhileTheServerIsAwayLandsOnceItIsBack(): void
    {
        $cache = new TempProject();
        $files = FakeRemoteTransport::over($this->remote(), $this->spec(), $cache->dir);
        $files->listed($cache->dir);
        $this->await(static fn (): bool => \is_file($cache->path('README.md')), what: 'the first listing');

        $this->server()->stop();
        $files->dumpFile($cache->path('NOTES.md'), "written while away\n");
        $this->await(static fn (): bool => \str_contains($files->statusCells()[0]->text, Glyphs::OUT_OF_TOUCH), what: 'the link to be seen down');
        Assert::assertSame(1, $files->pending(), 'kept, not dropped');

        $this->server()->start();
        $this->await(static fn (): bool => 0 === $files->pending(), timeout: 15.0, what: 'the save to land');

        Assert::assertSame("written while away\n", \file_get_contents($this->server()->root.'/NOTES.md'));
        Assert::assertStringNotContainsString(Glyphs::OUT_OF_TOUCH, $files->statusCells()[0]->text);
        $files->close();
    }
}
