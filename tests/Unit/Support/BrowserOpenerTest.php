<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\BrowserOpener;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\TempProject;

final class BrowserOpenerTest extends TestCase
{
    use PreservesPath;

    /** The shims' folder: a TempProject, so it goes with the test rather than staying in the temp dir. */
    private ?TempProject $shims = null;

    /** A temp dir holding executable shims named $names, put first on PATH. */
    private function shimPath(string ...$names): string
    {
        $this->shims = new TempProject();
        $dir = \rtrim($this->shims->dir, characters: '/');
        foreach ($names as $name) {
            \file_put_contents($dir.'/'.$name, data: "#!/bin/sh\n");
            \chmod($dir.'/'.$name, permissions: 0o755);
        }
        \putenv('PATH='.$dir);

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
        \putenv('PATH=/nonexistent');
        $this->assertNull(BrowserOpener::command('https://x.test', 'Darwin'));
        $this->assertNull(BrowserOpener::command('https://x.test', 'Linux'));
    }

    public function testDefaultFamilyIsThisMachine(): void
    {
        // Whatever this machine resolves to, the shape is stable: null or an
        // argv whose last element is the URL.
        $command = BrowserOpener::command('https://x.test');
        if (null === $command) {
            $this->assertNull($command);

            return;
        }

        $this->assertSame('https://x.test', \array_last($command));
    }
}
