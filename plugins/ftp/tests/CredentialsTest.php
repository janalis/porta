<?php

declare(strict_types=1);

namespace Porta\FtpPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteSpec;
use Porta\FtpPlugin\Credentials;
use Porta\Tests\Support\TempProject;

/** A password kept where only this user can read it, in the netrc shape curl reads. */
final class CredentialsTest extends TestCase
{
    public function testItKeepsAPasswordPrivatelyAndForgetsIt(): void
    {
        $home = new TempProject();
        $spec = new RemoteSpec('ftp', 'example.org', '/', 'me');

        $this->assertNull(Credentials::find($home->dir, $spec));
        $file = Credentials::store($home->dir, $spec, 'pa ss"w\\rd');

        $this->assertSame(Credentials::file($home->dir, $spec), $file);
        $this->assertSame($file, Credentials::find($home->dir, $spec));
        $this->assertSame("machine example.org login me password \"pa ss\\\"w\\\\rd\"\n", \file_get_contents((string) $file));
        $this->assertSame(0o600, \fileperms((string) $file) & 0o777);
        $this->assertSame(0o700, \fileperms(\dirname((string) $file)) & 0o777);
        $this->assertFileDoesNotExist($file.'.tmp');

        Credentials::forget($home->dir, $spec);
        Credentials::forget($home->dir, $spec);
        $this->assertNull(Credentials::find($home->dir, $spec));
    }

    public function testAHomeThatCannotHoldItSaysSo(): void
    {
        $home = new TempProject(['.porta' => 'a file, not a directory']);

        $this->assertNull(Credentials::store($home->dir, new RemoteSpec('ftp', 'h', '/', 'me'), 'x'));

        $readOnly = new TempProject(['.porta/ftp/.keep' => '']);
        \chmod($readOnly->path('.porta/ftp'), permissions: 0o500);
        try {
            $this->assertNull(Credentials::store($readOnly->dir, new RemoteSpec('ftp', 'h', '/', 'me'), 'x'));
        } finally {
            \chmod($readOnly->path('.porta/ftp'), permissions: 0o700);
        }
    }

    public function testATokenIsQuotedOnlyWhenItMustBe(): void
    {
        $this->assertSame('plain', Credentials::token('plain'));
        $this->assertSame('""', Credentials::token(''));
        $this->assertSame('"a\tb"', Credentials::token("a\tb"));
    }
}
