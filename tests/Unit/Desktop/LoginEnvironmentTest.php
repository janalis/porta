<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\TestCase;
use Porta\Desktop\DesktopSession;
use Porta\Desktop\LoginEnvironment;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\TempProject;

/** What a login shell says the environment is, trusted only as far as its answer is well formed. */
final class LoginEnvironmentTest extends TestCase
{
    use PreservesPath;

    private const string SENTINEL = '__PORTA_DESKTOP_ENVIRONMENT__';

    /** @param list<string> $entries */
    private static function answer(array $entries, string $noise = ''): string
    {
        return $noise.self::SENTINEL.\implode("\0", $entries)."\0".self::SENTINEL;
    }

    /** @return array<string, string> */
    private static function resolved(?string $output): array
    {
        return new LoginEnvironment(static fn (): ?string => $output)->resolve(['SHELL' => '/bin/zsh']);
    }

    public function testTheShellsVariablesAreTakenAndWhatAProfilePrintsIsNot(): void
    {
        $this->assertSame(
            ['PATH' => '/opt/homebrew/bin:/usr/bin', 'EMPTY' => '', 'WITH_EQUALS' => 'a=b', 'MULTI' => "one\ntwo"],
            self::resolved(self::answer(['PATH=/opt/homebrew/bin:/usr/bin', 'EMPTY=', 'WITH_EQUALS=a=b', "MULTI=one\ntwo"], noise: "Welcome!\n")),
        );
    }

    public function testWhatIdentifiesThisProcessIsNeverOverwritten(): void
    {
        $this->assertSame(
            ['KEPT' => 'yes'],
            self::resolved(self::answer([
                'PORTA_DESKTOP=0',
                'PORTA_DESKTOP_TOKEN=x',
                'TERM=dumb',
                'TERM_PROGRAM=x',
                'COLORTERM=no',
                'TMUX=1',
                'TMUX_PANE=%1',
                'WSL_DISTRO_NAME=x',
                'PWD=/',
                'OLDPWD=/',
                'SHLVL=9',
                '_=/usr/bin/env',
                'KEPT=yes',
                'not-an-assignment',
                '=nameless',
            ])),
        );
    }

    public function testAnAnswerWithoutBothSentinelsIsNoAnswer(): void
    {
        $this->assertSame([], self::resolved(null));
        $this->assertSame([], self::resolved(''));
        $this->assertSame([], self::resolved(self::SENTINEL."PATH=/half\0"));
    }

    public function testTheLoginShellIsAskedAndAMissingOneFallsBackToSh(): void
    {
        $asked = [];
        $environment = new LoginEnvironment(static function (array $argv, float $deadline, string $folder) use (&$asked): ?string {
            $asked[] = [$argv[0], \array_slice($argv, 1, 3), $deadline, $folder];

            return null;
        });

        $environment->resolve(['SHELL' => '/bin/zsh']);
        $environment->resolve(['SHELL' => 'zsh']);
        $environment->resolve(['SHELL' => false]);

        $this->assertSame(['/bin/zsh', '/bin/sh', '/bin/sh'], \array_column($asked, 0));
        $this->assertSame(['-i', '-l', '-c'], $asked[0][1]);
        $this->assertSame(5.0, $asked[0][2]);
    }

    public function testTheShellNeverStartsInTheProject(): void
    {
        $disk = new TempProject([
            'home/.keep' => '',
            'project/evil' => "#!/bin/sh\ntouch \"\$CANARY\"\n",
            'shell' => "#!/bin/sh\nexport STARTED_IN=\"\$PWD\"\n./evil 2>/dev/null\nshift 3\nexec /bin/sh -c \"\$1\"\n",
        ]);
        \chmod($disk->path('shell'), 0o755);
        \chmod($disk->path('project/evil'), 0o755);
        $canary = $disk->path('canary');
        \putenv('CANARY='.$canary);
        $cwd = (string) \getcwd();
        \chdir($disk->path('project'));
        try {
            $resolved = new LoginEnvironment()->resolve(['SHELL' => $disk->path('shell'), 'HOME' => $disk->path('home')]);
        } finally {
            \chdir($cwd);
            \putenv('CANARY');
        }

        $this->assertSame(\realpath($disk->path('home')), \realpath($resolved['STARTED_IN'] ?? ''));
        $this->assertFileDoesNotExist($canary, 'the project\'s own program ran');
    }

    public function testWithoutAUsableHomeTheShellStartsAtTheRoot(): void
    {
        $folders = [];
        $environment = new LoginEnvironment(static function (array $argv, float $deadline, string $folder) use (&$folders): ?string {
            $folders[] = $folder;

            return null;
        });

        $environment->resolve(['HOME' => 'relative']);
        $environment->resolve(['HOME' => '/no/such/folder']);
        $environment->resolve([]);

        $this->assertSame(['/', '/', '/'], $folders);
    }

    public function testARealShellAnswersFromItsOwnSession(): void
    {
        $disk = new TempProject([
            'shell' => "#!/bin/sh\nPORTA_LOGIN_PROBE=\$(ps -o sid= -p \$\$ 2>/dev/null | tr -d ' ')\nexport PORTA_LOGIN_PROBE\nexport FROM_PROFILE=yes\nshift 3\nexec /bin/sh -c \"\$1\"\n",
        ]);
        \chmod($disk->path('shell'), 0o755);

        $resolved = new LoginEnvironment()->resolve(['SHELL' => $disk->path('shell')]);

        $this->assertSame('yes', $resolved['FROM_PROFILE'] ?? null);
    }

    public function testAProfileThatHangsDoesNotHoldTheLaunch(): void
    {
        $disk = new TempProject(['shell' => "#!/bin/sh\nexec sleep 30\n"]);
        \chmod($disk->path('shell'), 0o755);

        $started = \microtime(true);
        $resolved = new LoginEnvironment(deadline: 0.2)->resolve(['SHELL' => $disk->path('shell')]);

        $this->assertSame([], $resolved);
        $this->assertLessThan(5.0, \microtime(true) - $started);
    }

    public function testATerminalLaunchIsLeftAlone(): void
    {
        \putenv(DesktopSession::FLAG);
        $path = (string) \getenv('PATH');
        $server = $_SERVER;

        LoginEnvironment::apply();

        $this->assertSame($path, \getenv('PATH'));
        $this->assertSame($server, $_SERVER);
    }

    public function testADesktopLaunchWritesBothEnvironmentTables(): void
    {
        $disk = new TempProject(['shell' => "#!/bin/sh\nexport PORTA_TEST_LOGIN=1 FROM_LOGIN_SHELL=applied\nshift 3\nexec /bin/sh -c \"\$1\"\n"]);
        \chmod($disk->path('shell'), 0o755);
        $shell = \getenv('SHELL');
        \putenv('SHELL='.$disk->path('shell'));
        \putenv(DesktopSession::FLAG.'=1');
        \putenv(DesktopSession::TOKEN.'=0123456789abcdef0123456789abcdef');
        try {
            LoginEnvironment::apply();

            $this->assertSame('applied', \getenv('FROM_LOGIN_SHELL'));
            $this->assertSame('applied', $_SERVER['FROM_LOGIN_SHELL'] ?? null);
        } finally {
            \putenv(false === $shell ? 'SHELL' : 'SHELL='.$shell);
            \putenv(DesktopSession::FLAG);
            \putenv(DesktopSession::TOKEN);
            \putenv('FROM_LOGIN_SHELL');
            \putenv('PORTA_TEST_LOGIN');
            unset($_SERVER['FROM_LOGIN_SHELL']);
        }
    }
}
