<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\DesktopUpdate;
use Porta\Console\SelfUpdate;
use Porta\Install\Installation;
use Porta\Install\InstallMethod;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Filesystem\Filesystem;

/** Each package replaced the way it was installed, against a fake GitHub and fake system tools. */
final class DesktopUpdateTest extends TestCase
{
    private TempProject $disk;

    /** @var list<list<string>> */
    private array $ran = [];

    /** @var array<string, int> exit codes by command name */
    private array $exits = [];

    /** What the download serves for the package; the checksum file always says NEW. */
    private string $served = 'NEW';

    /** Whether the copy out of the disk image lands where it was asked to. */
    private bool $copies = true;

    #[\Override]
    protected function setUp(): void
    {
        $this->disk = new TempProject([
            'Applications/Porta.app/Contents/Resources/runtime/porta.phar' => 'OLD',
            'Applications/Porta.app/Contents/Resources/runtime/desktop-package' => "porta-desktop-macos-arm64.dmg\n",
            'local/porta-desktop/runtime/porta.phar' => 'OLD',
            'local/porta-desktop/runtime/desktop-package' => "porta-desktop-linux-x86_64.tar.gz\n",
            'usr/porta-desktop/runtime/porta.phar' => 'OLD',
            'usr/porta-desktop/runtime/desktop-package' => "porta-desktop-linux-x86_64.deb\n",
            'rpm/porta-desktop/runtime/porta.phar' => 'OLD',
            'rpm/porta-desktop/runtime/desktop-package' => "porta-desktop-linux-x86_64.rpm\n",
            'Programs/Porta/runtime/porta.phar' => 'OLD',
            'Programs/Porta/runtime/desktop-package' => "porta-desktop-windows.exe\n",
            'loose/runtime/porta.phar' => 'OLD',
            'loose/runtime/desktop-package' => "porta-desktop-macos-arm64.dmg\n",
        ]);
    }

    private function install(string $phar): void
    {
        $run = function (array $command, ?string $stdin = null): array {
            $this->ran[] = $command;

            return [$this->fake($command), 'curl' === $command[0] && !\in_array('-o', $command, strict: true) ? $this->release() : ''];
        };
        new DesktopUpdate(new SelfUpdate($run, []), $run)->install('v2.0.0', new Installation(InstallMethod::Desktop, $this->disk->path($phar)));
    }

    private function release(): string
    {
        $assets = [];
        foreach ([
            'porta-desktop-macos-arm64.dmg',
            'porta-desktop-linux-x86_64.tar.gz',
            'porta-desktop-linux-x86_64.deb',
            'porta-desktop-linux-x86_64.rpm',
            'porta-desktop-windows.exe',
            'porta-desktop.sha256',
        ] as $name) {
            $assets[] = ['name' => $name, 'url' => 'https://api/'.$name];
        }

        return (string) \json_encode(['tag_name' => 'v2.0.0', 'assets' => $assets], \JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $command */
    private function fake(array $command): int
    {
        $fs = new Filesystem();
        $out = \array_search('-o', $command, strict: true);
        if ('curl' === $command[0] && false !== $out) {
            $file = $command[$out + 1];
            $sums = '';
            foreach (['porta-desktop-macos-arm64.dmg', 'porta-desktop-linux-x86_64.tar.gz', 'porta-desktop-linux-x86_64.deb', 'porta-desktop-linux-x86_64.rpm', 'porta-desktop-windows.exe'] as $name) {
                $sums .= \hash('sha256', data: 'NEW').'  '.$name."\n";
            }
            \file_put_contents($file, \str_ends_with($file, '.sha256') ? $sums : $this->served);
        }
        if ('hdiutil' === $command[0] && 'attach' === $command[1]) {
            $fs->dumpFile($command[6].'/Porta.app/Contents/Resources/runtime/porta.phar', 'NEW');
        }
        if ('ditto' === $command[0] && $this->copies) {
            $fs->mirror($command[1], $command[2]);
        }
        if ('tar' === $command[0]) {
            $fs->dumpFile($command[4].'/lib/porta-desktop/runtime/porta.phar', 'NEW');
        }

        return $this->exits[$command[0]] ?? 0;
    }

    /** @return list<string> the commands run, by name */
    private function names(): array
    {
        return \array_values(\array_unique(\array_map(static fn (array $command): string => $command[0], $this->ran)));
    }

    public function testADiskImageReplacesTheBundleOnceItsSignatureHolds(): void
    {
        $this->install('Applications/Porta.app/Contents/Resources/runtime/porta.phar');

        $this->assertSame('NEW', \file_get_contents($this->disk->path('Applications/Porta.app/Contents/Resources/runtime/porta.phar')));
        $this->assertSame(['curl', 'hdiutil', 'ditto', 'codesign'], $this->names());
        $this->assertContains(['hdiutil', 'detach', '-quiet'], \array_map(static fn (array $c): array => \array_slice($c, 0, 3), $this->ran), 'the image is put away');
        $this->assertSame(['Porta.app'], \array_values(\array_diff((array) \scandir($this->disk->path('Applications')), ['.', '..'])), 'nothing staged or old is left beside it');
    }

    public function testABrokenSignatureLeavesTheApplicationAsItWas(): void
    {
        $this->exits['codesign'] = 1;

        $this->assertFailsWith('is not intact', 'Applications/Porta.app/Contents/Resources/runtime/porta.phar');
        $this->assertSame(['Porta.app'], \array_values(\array_diff((array) \scandir($this->disk->path('Applications')), ['.', '..'])));
    }

    public function testADownloadThatIsNotTheReleasedOneChangesNothing(): void
    {
        $this->served = 'TAMPERED';

        $this->assertFailsWith('does not match its checksum', 'Applications/Porta.app/Contents/Resources/runtime/porta.phar');
        $this->assertNotContains('hdiutil', $this->names());
    }

    public function testAnImageThatWillNotOpenChangesNothing(): void
    {
        $this->exits['hdiutil'] = 1;

        $this->assertFailsWith('would not open', 'Applications/Porta.app/Contents/Resources/runtime/porta.phar');
    }

    public function testASwapThatFailsPutsTheOldOneBack(): void
    {
        $this->copies = false;

        $this->assertFailsWith('could not be replaced', 'Applications/Porta.app/Contents/Resources/runtime/porta.phar');
    }

    public function testADiskImageOutsideABundleIsRefused(): void
    {
        $this->assertFailsWith('not inside an application bundle', 'loose/runtime/porta.phar');
    }

    public function testATarballReplacesTheFolderInTheUsersOwnPlace(): void
    {
        $this->install('local/porta-desktop/runtime/porta.phar');

        $this->assertSame('NEW', \file_get_contents($this->disk->path('local/porta-desktop/runtime/porta.phar')));
        $this->assertSame(['porta-desktop'], \array_values(\array_diff((array) \scandir($this->disk->path('local')), ['.', '..'])));
    }

    public function testATarballWithoutTheApplicationChangesNothing(): void
    {
        $this->exits['tar'] = 0;
        $run = function (array $command, ?string $stdin = null): array {
            $this->ran[] = $command;
            if ('tar' === $command[0]) {
                return [0, ''];
            }

            return [$this->fake($command), 'curl' === $command[0] && !\in_array('-o', $command, strict: true) ? $this->release() : ''];
        };

        try {
            new DesktopUpdate(new SelfUpdate($run, []), $run)->install('v2.0.0', new Installation(InstallMethod::Desktop, $this->disk->path('local/porta-desktop/runtime/porta.phar')));
            $this->fail('an empty package was installed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not hold the application', $e->getMessage());
        }
        $this->assertSame('OLD', \file_get_contents($this->disk->path('local/porta-desktop/runtime/porta.phar')));
    }

    public function testATarballThatWillNotUnpackChangesNothing(): void
    {
        $this->exits['tar'] = 2;

        $this->assertFailsWith('would not unpack', 'local/porta-desktop/runtime/porta.phar');
    }

    public function testASystemPackageGoesThroughThePackageManager(): void
    {
        $this->install('usr/porta-desktop/runtime/porta.phar');
        $this->install('rpm/porta-desktop/runtime/porta.phar');

        $managers = \array_values(\array_filter($this->ran, static fn (array $command): bool => 'pkexec' === $command[0]));
        $this->assertSame(['pkexec', 'apt-get', 'install', '-y'], \array_slice($managers[0], 0, 4));
        $this->assertStringEndsWith('/porta-desktop-linux-x86_64.deb', $managers[0][4]);
        $this->assertSame(['pkexec', 'dnf', 'install', '-y'], \array_slice($managers[1], 0, 4));
    }

    public function testARefusedPackageManagerSaysHowToInstallByHand(): void
    {
        $this->exits['pkexec'] = 126;

        $this->assertFailsWith('Install it yourself: sudo apt install ', 'usr/porta-desktop/runtime/porta.phar');
    }

    public function testTheWindowsInstallerIsStartedApartAndKept(): void
    {
        $this->install('Programs/Porta/runtime/porta.phar');

        $started = \end($this->ran);
        $this->assertSame('/bin/sh', $started[0]);
        $this->assertStringContainsString('/VERYSILENT', $started[2]);
        $this->assertFileExists($this->disk->path('Programs/Porta/updates/porta-desktop-windows.exe'), 'it must still be there when it runs');
    }

    public function testAReleaseWithoutThePackageChangesNothing(): void
    {
        $run = function (array $command, ?string $stdin = null): array {
            $this->ran[] = $command;

            return [0, (string) \json_encode(['assets' => [['name' => 'porta.phar', 'url' => 'https://api/porta.phar']]], \JSON_THROW_ON_ERROR)];
        };

        $this->expectExceptionMessageIsOrContains('v2.0.0 has no porta-desktop-macos-arm64.dmg');
        new DesktopUpdate(new SelfUpdate($run, []), $run)->install(
            'v2.0.0',
            new Installation(InstallMethod::Desktop, $this->disk->path('Applications/Porta.app/Contents/Resources/runtime/porta.phar')),
        );
    }

    private function assertFailsWith(string $problem, string $phar): void
    {
        try {
            $this->install($phar);
            $this->fail('the update went through');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($problem, $e->getMessage());
        }
        $this->assertSame('OLD', \file_get_contents($this->disk->path($phar)), 'the installed application is untouched');
    }
}
