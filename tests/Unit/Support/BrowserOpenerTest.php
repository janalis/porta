<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\BrowserOpener;

final class BrowserOpenerTest extends TestCase
{
    private ?string $savedPath = null;

    protected function setUp(): void
    {
        $this->savedPath = getenv('PATH') ?: null;
    }

    protected function tearDown(): void
    {
        putenv(null === $this->savedPath ? 'PATH' : 'PATH='.$this->savedPath);
    }

    /** A temp dir holding executable shims named $names, put first on PATH. */
    private function shimPath(string ...$names): string
    {
        $dir = sys_get_temp_dir().'/porta-opener-'.bin2hex(random_bytes(4));
        mkdir($dir);
        foreach ($names as $name) {
            file_put_contents($dir.'/'.$name, "#!/bin/sh\n");
            chmod($dir.'/'.$name, 0o755);
        }
        putenv('PATH='.$dir);

        return $dir;
    }

    public function testDarwinUsesOpen(): void
    {
        $dir = $this->shimPath('open');
        $this->assertSame([$dir.'/open', 'https://x.test'], BrowserOpener::command('https://x.test', 'Darwin'));
    }

    public function testLinuxAndFriendsUseXdgOpen(): void
    {
        $dir = $this->shimPath('xdg-open');
        $this->assertSame([$dir.'/xdg-open', 'https://x.test'], BrowserOpener::command('https://x.test', 'Linux'));
        $this->assertSame([$dir.'/xdg-open', 'https://x.test'], BrowserOpener::command('https://x.test', 'BSD'));
    }

    public function testWindowsUsesTheStartBuiltin(): void
    {
        $this->assertSame(['cmd', '/c', 'start', '', 'https://x.test'], BrowserOpener::command('https://x.test', 'Windows'));
    }

    public function testMissingOpenerYieldsNull(): void
    {
        putenv('PATH=/nonexistent');
        $this->assertNull(BrowserOpener::command('https://x.test', 'Darwin'));
        $this->assertNull(BrowserOpener::command('https://x.test', 'Linux'));
    }

    public function testDefaultFamilyIsThisMachine(): void
    {
        // Whatever this machine resolves to, the shape is stable: null or an
        // argv whose last element is the URL.
        $command = BrowserOpener::command('https://x.test');
        if (null !== $command) {
            $this->assertSame('https://x.test', $command[array_key_last($command)]);
        } else {
            $this->assertNull($command);
        }
    }
}
