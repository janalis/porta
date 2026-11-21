<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\TestCase;
use Porta\Desktop\DesktopSession;
use Porta\Desktop\LaunchTheme;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;

/** The first frame's theme: one chosen in a file, or else the window's look. */
final class LaunchThemeTest extends TestCase
{
    use PreservesPortaHome;

    private TempProject $disk;

    #[\Override]
    protected function setUp(): void
    {
        $this->disk = new TempProject(['home/.keep' => '', 'project/.keep' => '']);
        \putenv('PORTA_HOME='.$this->disk->path('home'));
    }

    private static function window(?string $appearance): DesktopSession
    {
        return new DesktopSession(active: true, token: \str_repeat('a', 32), appearance: $appearance);
    }

    private function theme(?string $appearance, string $configured = 'dark'): string
    {
        return LaunchTheme::for($this->disk->path('project'), ['theme' => $configured], self::window($appearance));
    }

    public function testNothingChosenFollowsTheWindow(): void
    {
        $this->assertSame('light', $this->theme('light'));
    }

    public function testWithoutAWindowsWordTheSettingStands(): void
    {
        $this->assertSame('dark', $this->theme(null));
        $this->assertSame('high-contrast', $this->theme(null, 'high-contrast'));
    }

    public function testAThemeChosenForTheProjectOutranksTheWindow(): void
    {
        $this->disk->write('project/.porta.json', '{"settings": {"theme": "high-contrast"}}');

        $this->assertSame('high-contrast', $this->theme('light', 'high-contrast'));
    }

    public function testAThemeChosenForEveryProjectOutranksTheWindow(): void
    {
        $this->disk->write('home/.porta/config.json', '{"settings": {"theme": "dark"}}');

        $this->assertSame('dark', $this->theme('light'));
    }

    public function testNoSettingAtAllIsDark(): void
    {
        $this->assertSame('dark', LaunchTheme::for($this->disk->path('project'), [], self::window(null)));
    }
}
