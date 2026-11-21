<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Desktop\DesktopSession;

/** Desktop behaviour needs the flag and a real token: anything less is a terminal launch. */
final class DesktopSessionTest extends TestCase
{
    private const string SESSION = '0123456789abcdef0123456789abcdef';

    public function testTheFlagAndAWellFormedTokenMakeADesktopSession(): void
    {
        $session = DesktopSession::fromEnvironment([DesktopSession::FLAG => '1', DesktopSession::TOKEN => self::SESSION]);

        $this->assertTrue($session->active);
        $this->assertSame(self::SESSION, $session->token);
    }

    public function testOnlyAHostThatSaysSoHasASystemMenu(): void
    {
        $session = [DesktopSession::FLAG => '1', DesktopSession::TOKEN => self::SESSION];

        $this->assertFalse(DesktopSession::fromEnvironment($session)->systemMenu);
        $this->assertFalse(DesktopSession::fromEnvironment([...$session, DesktopSession::MENU => 'window'])->systemMenu);
        $this->assertTrue(DesktopSession::fromEnvironment([...$session, DesktopSession::MENU => 'system'])->systemMenu);
        $this->assertFalse(DesktopSession::fromEnvironment([DesktopSession::MENU => 'system'])->systemMenu, 'not without a session');
    }

    public function testTheSystemsModeAtLaunchIsLightOrDarkOrUnknown(): void
    {
        $session = [DesktopSession::FLAG => '1', DesktopSession::TOKEN => self::SESSION];

        $this->assertNull(DesktopSession::fromEnvironment($session)->appearance);
        $this->assertSame('light', DesktopSession::fromEnvironment([...$session, DesktopSession::APPEARANCE => 'light'])->appearance);
        $this->assertSame('dark', DesktopSession::fromEnvironment([...$session, DesktopSession::APPEARANCE => 'dark'])->appearance);
        $this->assertNull(DesktopSession::fromEnvironment([...$session, DesktopSession::APPEARANCE => 'sepia'])->appearance);
        $this->assertNull(DesktopSession::fromEnvironment([DesktopSession::APPEARANCE => 'light'])->appearance, 'not without a session');
    }

    public function testTheLookTheWindowReportsLaterReplacesTheOneAtLaunch(): void
    {
        $session = new DesktopSession(active: true, token: self::SESSION, systemMenu: true, appearance: 'light');

        $this->assertEquals(new DesktopSession(active: true, token: self::SESSION, systemMenu: true, appearance: 'dark'), $session->withAppearance('dark'));
        $this->assertSame($session, $session->withAppearance(null), 'nothing reported keeps the launch\'s');
    }

    /** @return iterable<string, array{array<string, string|false>}> */
    public static function terminalLaunches(): iterable
    {
        yield 'nothing set' => [[]];
        yield 'flag without a token' => [[DesktopSession::FLAG => '1']];
        yield 'token without the flag' => [[DesktopSession::TOKEN => self::SESSION]];
        yield 'flag switched off' => [[DesktopSession::FLAG => '0', DesktopSession::TOKEN => self::SESSION]];
        yield 'token too short' => [[DesktopSession::FLAG => '1', DesktopSession::TOKEN => 'abc123']];
        yield 'token not hex' => [[DesktopSession::FLAG => '1', DesktopSession::TOKEN => \str_repeat('z', 32)]];
        yield 'token with a trailing newline' => [[DesktopSession::FLAG => '1', DesktopSession::TOKEN => self::SESSION."\n"]];
        yield 'token unset' => [[DesktopSession::FLAG => '1', DesktopSession::TOKEN => false]];
    }

    /** @param array<string, string|false> $environment */
    #[DataProvider('terminalLaunches')]
    public function testAnythingElseIsATerminalLaunch(array $environment): void
    {
        $session = DesktopSession::fromEnvironment($environment);

        $this->assertFalse($session->active);
        $this->assertSame('', $session->token);
    }

    public function testTheCurrentSessionIsReadFromTheProcessEnvironment(): void
    {
        \putenv(DesktopSession::FLAG.'=1');
        \putenv(DesktopSession::TOKEN.'='.self::SESSION);
        try {
            $this->assertTrue(DesktopSession::current()->active);
        } finally {
            \putenv(DesktopSession::FLAG);
            \putenv(DesktopSession::TOKEN);
        }
        $this->assertFalse(DesktopSession::current()->active);
    }
}
