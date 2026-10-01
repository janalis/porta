<?php

declare(strict_types=1);

namespace Porta\FtpPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteEntry;
use Porta\Filesystem\RemoteFile;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;
use Porta\FtpPlugin\Credentials;
use Porta\FtpPlugin\FtpTransport;
use Porta\Tests\Support\ExercisesTransport;
use Porta\Tests\Support\TempProject;

/**
 * The curl command lines, run against a fake curl that serves a local
 * directory over the options the transport sends — so the URLs, the quoted
 * FTP commands and the stamps are exercised end to end, minus a server.
 */
final class FtpTransportTest extends TestCase
{
    use ExercisesTransport;

    private const string FAKE_CURL = __DIR__.'/fixtures/fake-curl';

    private TempProject $server;
    private FtpTransport $transport;

    protected function setUp(): void
    {
        $this->server = new TempProject(['README.md' => "# hi\n", 'src/App.php' => "<?php\n", 'a name.txt' => 'spaced']);
        $this->transport = new FtpTransport(new RemoteSpec('ftp', 'example.org', $this->server->dir), null, self::FAKE_CURL);
    }

    public function testItListsADirectoryFromMlsd(): void
    {
        $entries = $this->answer(fn (\Closure $done) => $this->transport->list('', $done));

        $this->assertIsArray($entries);
        $byName = [];
        foreach ($entries as $entry) {
            $this->assertInstanceOf(RemoteEntry::class, $entry);
            $byName[$entry->name] = $entry;
        }
        \ksort($byName);
        $this->assertSame(['README.md', 'a name.txt', 'src'], \array_keys($byName), 'not . and ..');
        $this->assertTrue($byName['src']->isDir);
        $this->assertEquals($this->stampOf($this->server, 'README.md'), $byName['README.md']->stamp, 'the same clock a fetch reads');
    }

    public function testListingAMissingDirectorySaysWhy(): void
    {
        $this->assertSame('curl: (9) Server denied you to change to the given directory', $this->answer(fn (\Closure $done) => $this->transport->list('nope', $done)));
    }

    public function testItFetchesAFileWithItsStamp(): void
    {
        $file = $this->answer(fn (\Closure $done) => $this->transport->fetch('a name.txt', $done));

        $this->assertInstanceOf(RemoteFile::class, $file);
        $this->assertSame('spaced', $file->bytes);
        $this->assertEquals($this->stampOf($this->server, 'a name.txt'), $file->stamp);
    }

    public function testItWalksTheTreeOneDirectoryAtATime(): void
    {
        $this->server->write('vendor/x/Skipped.php', '');
        $this->server->write('deep/er/Found.php', '<?php');

        $entries = $this->answer(fn (\Closure $done): bool => $this->transport->walk('', ['vendor'], $done));

        $this->assertIsArray($entries);
        $byPath = [];
        foreach ($entries as $entry) {
            $this->assertInstanceOf(RemoteEntry::class, $entry);
            $byPath[$entry->name] = $entry;
        }
        \ksort($byPath);
        $this->assertSame(['README.md', 'a name.txt', 'deep', 'deep/er', 'deep/er/Found.php', 'src', 'src/App.php'], \array_keys($byPath));
        $this->assertEquals($this->stampOf($this->server, 'deep/er/Found.php'), $byPath['deep/er/Found.php']->stamp);
    }

    public function testAWalkThatCannotListSaysWhy(): void
    {
        $this->assertIsString($this->answer(fn (\Closure $done): bool => $this->transport->walk('nope', [], $done)));
    }

    /**
     * A password kept while the project is open is used from the next call —
     * the transport is not rebuilt — and a refused login is said, so the
     * plugin can ask for one.
     */
    public function testARefusedLoginIsSaidAndAPasswordKeptSinceIsUsed(): void
    {
        $home = new TempProject();
        $spec = new RemoteSpec('ftp', 'example.org', $this->server->dir, 'me');
        $refusals = 0;
        $transport = new FtpTransport($spec, Credentials::file($home->dir, $spec), self::FAKE_CURL, static function () use (&$refusals): void {
            ++$refusals;
        });
        \putenv('FAKE_FTP_PASSWORD=right');
        try {
            $this->assertSame('curl: (67) Access denied: 530', $this->answer(static fn (\Closure $done) => $transport->list('', $done)));
            $this->assertSame(1, $refusals);

            Credentials::store($home->dir, $spec, 'right');

            $this->assertIsArray($this->answer(static fn (\Closure $done) => $transport->list('', $done)));
            $this->assertSame(1, $refusals, 'not refused this time');
        } finally {
            \putenv('FAKE_FTP_PASSWORD');
        }
    }

    public function testFetchingAMissingFileSaysWhy(): void
    {
        $this->assertSame('curl: (19) Given file does not exist', $this->answer(fn (\Closure $done) => $this->transport->fetch('missing', $done)));
    }

    public function testItWritesThroughAPartialNameAndAnswersTheNewStamp(): void
    {
        $stamp = $this->answer(fn (\Closure $done) => $this->transport->put('deep/new.txt', "fresh\n", null, $done));

        $this->assertSame("fresh\n", \file_get_contents($this->server->path('deep/new.txt')));
        $this->assertEquals($this->stampOf($this->server, 'deep/new.txt'), $stamp);
        $this->assertSame([], \glob($this->server->path('deep/.porta-upload*')));
    }

    public function testAWriteOverTheStampItWasFetchedAtReplacesTheFile(): void
    {
        $this->assertInstanceOf(RemoteStamp::class, $this->answer(fn (\Closure $done) => $this->transport->put('README.md', 'mine', $this->stampOf($this->server, 'README.md'), $done)));
        $this->assertSame('mine', \file_get_contents($this->server->path('README.md')));
    }

    public function testAWriteOverAChangedOrDeletedFileIsRefused(): void
    {
        $stale = new RemoteStamp(1, 1);

        $this->assertNull($this->answer(fn (\Closure $done) => $this->transport->put('README.md', 'mine', $stale, $done)));
        $this->assertNull($this->answer(fn (\Closure $done) => $this->transport->put('gone.txt', 'mine', $stale, $done)));
        $this->assertSame("# hi\n", \file_get_contents($this->server->path('README.md')));
        $this->assertFileDoesNotExist($this->server->path('gone.txt'));
    }

    public function testAFailedUploadSaysWhy(): void
    {
        $this->server->write('file', 'x');

        $this->assertIsString($this->answer(fn (\Closure $done) => $this->transport->put('file/under.txt', 'x', null, $done)));
    }

    public function testDirectoriesAreMadeMovedAndRemovedWithWhatTheyHold(): void
    {
        $this->assertTheStructuralVerbs($this->transport, $this->server);
    }

    public function testRemovalsThatFailSayWhy(): void
    {
        $this->assertSame('curl: (21) QUOT command failed with 550', $this->answer(fn (\Closure $done) => $this->transport->remove('missing', false, $done)));
        $this->assertIsString($this->answer(fn (\Closure $done) => $this->transport->remove('missing', true, $done)), 'a directory that cannot be listed');
        \chmod($this->server->path('src'), permissions: 0o500);
        try {
            $this->assertIsString($this->answer(fn (\Closure $done) => $this->transport->remove('src', true, $done)), 'what it holds cannot go');
        } finally {
            \chmod($this->server->path('src'), permissions: 0o755);
        }
    }

    public function testANameWithALineBreakIsNeverSent(): void
    {
        $this->assertSame('a name with a line break in it cannot be sent over FTP', $this->answer(fn (\Closure $done) => $this->transport->remove("x\r\nDELE README.md", false, $done)));
        $this->assertSame('a name with a line break in it cannot be sent over FTP', $this->answer(fn (\Closure $done) => $this->transport->put("x\nDELE README.md", 'x', null, $done)));
        $this->assertFileExists($this->server->path('README.md'));
    }

    public function testTheCommandLineCarriesCredentialsAndTls(): void
    {
        $home = new TempProject(['x.netrc' => 'machine example.org login me password p']);
        $transport = new FtpTransport(new RemoteSpec('ftps', 'example.org', '/srv/my site', 'me', 2121), $home->path('x.netrc'));

        $this->assertSame(
            ['curl', '--silent', '--show-error', '--netrc-file', $home->path('x.netrc'), '--ssl-reqd', '--head', '--', 'ftp://example.org:2121/%2F/srv/my%20site/a.txt'],
            $transport->command(['--head'], $transport->url('a.txt')),
        );
        $this->assertNotContains('--netrc-file', new FtpTransport(new RemoteSpec('ftp', 'h', '/'), $home->path('none.netrc'))->command([], 'u'), 'no password kept: anonymous');
    }

    public function testUrlsAreAbsoluteOrRelativeToTheLoginDirectory(): void
    {
        $root = new FtpTransport(new RemoteSpec('ftp', 'h', '/'), null);
        $home = new FtpTransport(new RemoteSpec('ftp', 'h', '.'), null);

        $this->assertSame('ftp://h/%2F/', $root->directoryUrl(''));
        $this->assertSame('ftp://h/%2F/a/b.txt', $root->url('a/b.txt'));
        $this->assertSame('ftp://h/', $home->directoryUrl(''));
        $this->assertSame('ftp://h/site/', $home->directoryUrl('site'));
    }
}
