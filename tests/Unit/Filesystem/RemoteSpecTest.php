<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteSpec;

/** What a person types for a server's project, read the way scp and URLs read it. */
final class RemoteSpecTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, ?string, ?int}> */
    public static function specs(): iterable
    {
        yield 'scp with a user' => ['deploy@example.org:/srv/app', 'ssh', 'example.org', '/srv/app', 'deploy', null];
        yield 'scp without one' => ['example.org:/srv/app/', 'ssh', 'example.org', '/srv/app', null, null];
        yield 'scp relative' => ['example.org:sites/app', 'ssh', 'example.org', 'sites/app', null, null];
        yield 'scp home' => ['example.org:', 'ssh', 'example.org', '.', null, null];
        yield 'scp tilde path' => ['example.org:~/sites/app', 'ssh', 'example.org', 'sites/app', null, null];
        yield 'scp tilde alone' => ['example.org:~', 'ssh', 'example.org', '.', null, null];
        yield 'ssh url with a port' => ['ssh://deploy@example.org:2222/srv/app', 'ssh', 'example.org', '/srv/app', 'deploy', 2222];
        yield 'sftp url' => ['SFTP://example.org/srv/app', 'sftp', 'example.org', '/srv/app', null, null];
        yield 'ftp root' => ['ftp://me@ftp.example.org', 'ftp', 'ftp.example.org', '/', 'me', null];
        yield 'root, doubled slash' => ['ftp://ftp.example.org//', 'ftp', 'ftp.example.org', '/', null, null];
        yield 'ftps url, encoded' => ['ftps://me%40corp@example.org/my%20site/', 'ftps', 'example.org', '/my site', 'me@corp', null];
        yield 'url, relative' => ['ssh://example.org/~/app', 'ssh', 'example.org', 'app', null, null];
        yield 'url, home' => ['ssh://example.org/~', 'ssh', 'example.org', '.', null, null];
        yield 'url, password dropped' => ['ftp://me:secret@example.org/', 'ftp', 'example.org', '/', 'me', null];
        yield 'surrounding space' => ['  example.org:/a  ', 'ssh', 'example.org', '/a', null, null];
    }

    #[DataProvider('specs')]
    public function testItReadsWhatAPersonTypes(string $input, string $scheme, string $host, string $path, ?string $user, ?int $port): void
    {
        $spec = RemoteSpec::parse($input);

        $this->assertInstanceOf(RemoteSpec::class, $spec);
        $this->assertSame([$scheme, $host, $path, $user, $port], [$spec->scheme, $spec->host, $spec->path, $spec->user, $spec->port]);
        $this->assertEquals($spec, RemoteSpec::parse($spec->url()), 'its URL reads back as the same spec');
        $this->assertStringNotContainsString('secret', $spec->url());
    }

    /** @return iterable<string, array{string}> */
    public static function notSpecs(): iterable
    {
        yield 'a local path' => ['/home/me/app'];
        yield 'a relative one' => ['app'];
        yield 'an unknown scheme' => ['http://example.org/'];
        yield 'no host' => ['ssh:///srv/app'];
        yield 'a user but no host' => ['ssh://me@/srv/app'];
        yield 'a broken url' => ['ftp://:80'];
        yield 'empty' => [''];
    }

    #[DataProvider('notSpecs')]
    public function testWhatNamesNoServerIsNotOne(string $input): void
    {
        $this->assertNull(RemoteSpec::parse($input));
    }

    public function testTheUrlIsCanonical(): void
    {
        $this->assertSame('ssh://deploy@example.org:2222/srv/my%20app', new RemoteSpec('ssh', 'example.org', '/srv/my app', 'deploy', 2222)->url());
        $this->assertSame('ssh://example.org/~/app', new RemoteSpec('ssh', 'example.org', 'app')->url());
        $this->assertSame('ftp://example.org/~', new RemoteSpec('ftp', 'example.org', '.')->url());
    }

    public function testItsLabelAndName(): void
    {
        $spec = new RemoteSpec('ssh', 'example.org', '/srv/app', 'deploy');

        $this->assertSame('deploy@example.org:/srv/app', $spec->label());
        $this->assertSame('example.org:/', new RemoteSpec('ftp', 'example.org', '/')->label());
        $this->assertSame('app', $spec->name());
        foreach (['/', '.', '..', '~'] as $path) {
            $this->assertSame('example.org', new RemoteSpec('ssh', 'example.org', $path)->name(), $path.' names no folder');
        }
    }

    public function testTheIdIsStableAndFollowsTheUrl(): void
    {
        $spec = new RemoteSpec('ssh', 'example.org', '/srv/app');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $spec->id());
        $this->assertSame($spec->id(), RemoteSpec::parse('example.org:/srv/app/')?->id());
        $this->assertNotSame($spec->id(), new RemoteSpec('sftp', 'example.org', '/srv/app')->id());
    }

    public function testAPathInsideIsTheServers(): void
    {
        $this->assertSame('/srv/app', new RemoteSpec('ssh', 'h', '/srv/app')->remotePath(''));
        $this->assertSame('/srv/app/src/A.php', new RemoteSpec('ssh', 'h', '/srv/app')->remotePath('src/A.php'));
        $this->assertSame('/src/A.php', new RemoteSpec('ftp', 'h', '/')->remotePath('src/A.php'));
        $this->assertSame('src/A.php', new RemoteSpec('ssh', 'h', '.')->remotePath('src/A.php'));
        $this->assertSame('.', new RemoteSpec('ssh', 'h', '.')->remotePath(''));
    }
}
