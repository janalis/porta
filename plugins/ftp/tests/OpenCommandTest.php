<?php

declare(strict_types=1);

namespace Porta\FtpPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteSpec;
use Porta\FtpPlugin\Console\OpenCommand;
use Porta\FtpPlugin\Credentials;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Tester\CommandTester;

/** `porta ftp` asks for the password, logs in once to be sure of it, then opens Porta on the cache. */
final class OpenCommandTest extends TestCase
{
    private TempProject $home;
    /** @var list<list<string>> */
    private array $ran = [];
    private ?string $opened = null;

    protected function setUp(): void
    {
        $this->home = new TempProject();
    }

    /** @param array{int, string} $curl */
    private function command(array $curl = [0, '']): CommandTester
    {
        return new CommandTester(new OpenCommand(
            function (string $root): int {
                $this->opened = $root;

                return 0;
            },
            $this->home->dir,
            function (array $command) use ($curl): array {
                $this->ran[] = $command;

                return $curl;
            },
        ));
    }

    public function testItAsksForThePasswordKeepsItAndOpens(): void
    {
        $tester = $this->command();
        $tester->setInputs(['secret']);

        $this->assertSame(0, $tester->execute(['location' => 'ftps://me@example.org/site']));

        $spec = new RemoteSpec('ftps', 'example.org', '/site', 'me');
        $netrc = (string) Credentials::find($this->home->dir, $spec);
        $this->assertStringContainsString('password secret', (string) \file_get_contents($netrc));
        $this->assertStringContainsString('Password for me@example.org', $tester->getDisplay());
        $this->assertSame(
            [
                'curl',
                '--silent',
                '--show-error',
                '--connect-timeout',
                '10',
                '--speed-limit',
                '1',
                '--speed-time',
                '30',
                '--netrc-file',
                $netrc,
                '--ssl-reqd',
                '--list-only',
                '--output',
                '/dev/null',
                '--',
                'ftp://example.org/%2F/site/',
            ],
            $this->ran[0],
        );
        $this->assertSame('ftps://me@example.org/site', ProjectLocation::forRoot((string) $this->opened)->remote?->url());
    }

    public function testWithoutAUserItLogsInAnonymously(): void
    {
        $tester = $this->command();

        $this->assertSame(0, $tester->execute(['location' => 'ftp://example.org/pub']));

        $this->assertNotContains('--netrc-file', $this->ran[0]);
        $this->assertStringNotContainsString('Password', $tester->getDisplay());
    }

    public function testABarePathIsFtp(): void
    {
        $this->command()->execute(['location' => 'example.org:/pub']);

        $this->assertSame('ftp', ProjectLocation::forRoot((string) $this->opened)->remote?->scheme);
    }

    public function testAPasswordThatDoesNotLogInIsNotKept(): void
    {
        $tester = $this->command([67, "curl: (67) Access denied: 530\n"]);
        $tester->setInputs(['wrong']);

        $this->assertSame(1, $tester->execute(['location' => 'ftp://me@example.org/site']));

        $this->assertNull($this->opened);
        $this->assertNull(Credentials::find($this->home->dir, new RemoteSpec('ftp', 'example.org', '/site', 'me')));
        $this->assertStringContainsString('Cannot log in to example.org: curl: (67) Access denied: 530', $tester->getDisplay());
    }

    public function testAFailureWithNothingSaidSaysHowItEnded(): void
    {
        $tester = $this->command([7, '']);

        $tester->execute(['location' => 'ftp://example.org/']);

        $this->assertStringContainsString('Cannot log in to example.org: curl exited with 7', $tester->getDisplay());
    }

    public function testAPasswordThatCannotBeKeptSaysSo(): void
    {
        $this->home->write('.porta', 'a file, not a directory');
        $tester = $this->command();
        $tester->setInputs(['secret']);

        $this->assertSame(1, $tester->execute(['location' => 'ftp://me@example.org/']));
        $this->assertStringContainsString('Cannot keep the password in', $tester->getDisplay());
    }
}
