<?php

declare(strict_types=1);

namespace Porta\FtpPlugin\Tests;

use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteEntry;
use Porta\Filesystem\RemoteFile;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;
use Porta\FtpPlugin\Credentials;
use Porta\FtpPlugin\FtpTransport;
use Porta\Tests\Support\DummyServer;
use Porta\Tests\Support\ExercisesTransport;
use Porta\Tests\Support\PresencePeer;
use Porta\Tests\Support\ProvesPresenceOverAServer;
use Porta\Tests\Support\ProvesTheLinkOverAServer;
use Porta\Tests\Support\TempProject;

/**
 * The real curl against a real FTP server — {@see DummyServer::ftp()}, a
 * PHP one serving a directory — rather than a fake curl: the protocol
 * itself, passive data connections and all, and a server that can go away.
 * Presence is held to it with two Porta instances on one project there.
 *
 * Large: each look is several real processes, and a caret move waits out
 * the two seconds a stamp takes to settle.
 */
#[Large]
final class FtpRealServerTest extends TestCase
{
    use ProvesPresenceOverAServer;
    use ProvesTheLinkOverAServer;
    use ExercisesTransport;

    private TempProject $root;

    private DummyServer $server;

    private RemoteSpec $spec;

    protected function setUp(): void
    {
        $this->root = new TempProject(['README.md' => "# hi\n", 'src/App.php' => "<?php\n", 'src/A.php' => "<?php\n"]);
        $this->server = DummyServer::ftp($this->root->dir);
        $this->spec = new RemoteSpec('ftp', '127.0.0.1', '/', null, $this->server->port);
    }

    private function transport(?string $netrc = null, ?\Closure $refused = null): FtpTransport
    {
        return new FtpTransport($this->spec, $netrc, 'curl', $refused);
    }

    public function testEveryVerbOverTheWire(): void
    {
        $transport = $this->transport();
        $app = $this->root;

        $entries = $this->answer(static fn (\Closure $done) => $transport->list('', $done));
        $this->assertIsArray($entries);
        $names = \array_map(static fn (RemoteEntry $e): string => $e->name.($e->isDir ? '/' : ''), $entries);
        \sort($names);
        $this->assertSame(['README.md', 'src/'], $names);
        $this->assertEquals($this->stampOf($app, 'README.md'), \array_first(\array_filter($entries, static fn (RemoteEntry $e): bool => 'README.md' === $e->name))->stamp, 'MLSD and MDTM, one clock');

        $file = $this->answer(static fn (\Closure $done) => $transport->fetch('README.md', $done));
        $this->assertInstanceOf(RemoteFile::class, $file);
        $this->assertSame("# hi\n", $file->bytes);

        $written = $this->answer(static fn (\Closure $done) => $transport->put('README.md', "# changed\n", $file->stamp, $done));
        $this->assertInstanceOf(RemoteStamp::class, $written);
        $this->assertSame("# changed\n", \file_get_contents($this->root->path('README.md')));
        $this->assertNull($this->answer(static fn (\Closure $done) => $transport->put('README.md', 'lost', $file->stamp, $done)), 'changed since: refused');
        $this->assertInstanceOf(RemoteStamp::class, $this->answer(static fn (\Closure $done) => $transport->put('deep/new/file.txt', 'new', null, $done)));
        $this->assertSame('new', \file_get_contents($this->root->path('deep/new/file.txt')), 'the directories made on the way');

        $this->assertTheStructuralVerbs($transport, $app);
    }

    public function testAServerThatIsNotThereAndALoginItRefusesSayWhy(): void
    {
        $this->server->stop();
        $answer = $this->answer(fn (\Closure $done) => $this->transport()->list('', $done));
        $this->assertIsString($answer);
        $this->assertStringContainsString('(7)', $answer, 'curl could not connect');

        $this->server = DummyServer::ftp($this->root->dir, 'secret');
        $this->spec = new RemoteSpec('ftp', '127.0.0.1', '/', 'ada', $this->server->port);
        $home = new TempProject();
        $refused = 0;
        $transport = $this->transport(Credentials::file($home->dir, $this->spec), static function () use (&$refused): void {
            ++$refused;
        });
        $answer = $this->answer(static fn (\Closure $done) => $transport->list('', $done));
        $this->assertSame(1, $refused, 'a refused login is said, so the plugin can ask for the password');
        $this->assertIsString($answer);

        Credentials::store($home->dir, $this->spec, 'secret');
        $this->assertIsArray($this->answer(fn (\Closure $done) => $this->transport(Credentials::file($home->dir, $this->spec))->list('', $done)), 'with it, in');
    }

    private function remote(): FtpTransport
    {
        return $this->transport();
    }

    private function spec(): RemoteSpec
    {
        return $this->spec;
    }

    private function server(): DummyServer
    {
        return $this->server;
    }

    private function heartbeat(): float
    {
        return 30.0;
    }

    /** @return array{PresencePeer, PresencePeer} */
    private function peers(): array
    {
        return [
            new PresencePeer('Ada', $this->spec, $this->transport(...)),
            new PresencePeer('Bo', $this->spec, $this->transport(...)),
        ];
    }
}
