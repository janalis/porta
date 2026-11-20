<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Porta\Support\PathExecutables;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\TempProject;

final class PathExecutablesTest extends TestCase
{
    use PreservesPath;

    /** Stands for the machine: a folder of Linux tools, and a "drive" folder as WSL mounts Windows'. */
    private TempProject $machine;

    #[Before]
    protected function makeMachine(): void
    {
        $this->machine = new TempProject();
        foreach (['bin', 'c/Program Files/tool'] as $dir) {
            \mkdir($this->dir($dir), permissions: 0o755, recursive: true);
        }
    }

    #[After]
    protected function forgetWsl(): void
    {
        \putenv('WSL_DISTRO_NAME');
    }

    private function dir(string $relative): string
    {
        return \rtrim($this->machine->dir, characters: '/').'/'.$relative;
    }

    private function program(string $relative): string
    {
        $file = $this->dir($relative);
        \file_put_contents($file, data: "#!/bin/sh\n");
        \chmod($file, permissions: 0o755);

        return $file;
    }

    /** As WSL lays it out: the Windows drive's folder first, as `appendWindowsPath` can put it. */
    private function wsl(): PathExecutables
    {
        return new PathExecutables($this->dir('c/Program Files/tool').':'.$this->dir('bin'), drives: $this->dir(''));
    }

    public function testUnderWslAProgramIsFoundOnTheLinuxPath(): void
    {
        $tool = $this->program('bin/tool');

        $this->assertSame($tool, $this->wsl()->find('tool'));
    }

    public function testUnderWslTheWindowsDrivesAreNotSearched(): void
    {
        // Only there: a lookup that stats Windows' folders would find it.
        $this->program('c/Program Files/tool/tool');

        $this->assertNull($this->wsl()->find('tool'));
    }

    public function testUnderWslAFileThatIsNotExecutableIsNoProgram(): void
    {
        \file_put_contents($this->dir('bin/notes'), data: "text\n");

        $this->assertNull($this->wsl()->find('notes'));
    }

    public function testUnderWslTheFirstFolderWins(): void
    {
        \mkdir($this->dir('later'), permissions: 0o755);
        $first = $this->program('bin/tool');
        $this->program('later/tool');

        $this->assertSame($first, new PathExecutables($this->dir('bin').':'.$this->dir('later'), drives: $this->dir(''))->find('tool'));
    }

    public function testUnderWslAFolderNamedLikeTheProgramIsNoProgram(): void
    {
        \mkdir($this->dir('bin/tool'), permissions: 0o755);
        \mkdir($this->dir('later'), permissions: 0o755);
        $tool = $this->program('later/tool');

        $this->assertSame($tool, new PathExecutables($this->dir('bin').':'.$this->dir('later'), drives: $this->dir(''))->find('tool'));
    }

    public function testUnderWslAFolderWithATrailingSlashGivesAPlainPath(): void
    {
        $tool = $this->program('bin/tool');

        $this->assertSame($tool, new PathExecutables($this->dir('bin').'/', drives: $this->dir(''))->find('tool'));
    }

    public function testUnderWslAnEmptyEntryIsTheCurrentDirectory(): void
    {
        $this->program('bin/tool');
        $cwd = (string) \getcwd();
        \chdir($this->dir('bin'));
        try {
            $this->assertSame('./tool', new PathExecutables(':'.$this->dir('c'), drives: $this->dir(''))->find('tool'));
        } finally {
            \chdir($cwd);
        }
    }

    public function testUnderWslAnEntryMayNameTheProgramItself(): void
    {
        $tool = $this->program('bin/tool');

        $this->assertSame($tool, new PathExecutables($tool, drives: $this->dir(''))->find('tool'));
        $this->assertNull(new PathExecutables($tool, drives: $this->dir(''))->find('other'), 'only under its own name');
    }

    public function testTheDrivesAreWhereWslConfMountsThem(): void
    {
        $conf = $this->dir('wsl.conf');
        $this->assertSame('/mnt/', PathExecutables::driveRoot($conf), 'no wsl.conf: the default');

        \file_put_contents($conf, data: "[boot]\nsystemd = true\n");
        $this->assertSame('/mnt/', PathExecutables::driveRoot($conf), 'no automount root: the default');

        \file_put_contents($conf, data: "[automount]\nroot = /\noptions = \"metadata\"\n");
        $this->assertSame('/', PathExecutables::driveRoot($conf));

        \file_put_contents($conf, data: "[automount]\nroot = \"/windows\"\n");
        $this->assertSame('/windows/', PathExecutables::driveRoot($conf));

        // Raw INI keeps single quotes: wsl.conf allows them.
        \file_put_contents($conf, data: "[automount]\nroot = '/windows'\n");
        $this->assertSame('/windows/', PathExecutables::driveRoot($conf));
    }

    public function testUnderWslADriveItselfIsNotSearched(): void
    {
        // `/mnt/c` with nothing after it is the drive's root, as much Windows' as its folders.
        $this->program('c/tool');

        $this->assertNull(new PathExecutables($this->dir('c'), drives: $this->dir(''))->find('tool'));
    }

    public function testAFolderOnlyNamedLikeADriveIsSearched(): void
    {
        // `/mnt/cache/bin` is no Windows drive: one letter, then the end or a slash, is.
        \mkdir($this->dir('cache/bin'), permissions: 0o755, recursive: true);
        $tool = $this->program('cache/bin/tool');

        $this->assertSame($tool, new PathExecutables($this->dir('cache/bin'), drives: $this->dir(''))->find('tool'));
    }

    public function testElsewhereItIsSymfonysFinder(): void
    {
        $tool = $this->program('bin/tool');
        \putenv('PATH='.$this->dir('bin'));

        $this->assertSame($tool, new PathExecutables(null)->find('tool'));
        $this->assertNull(new PathExecutables(null)->find('absent-tool'));
    }

    public function testHereSearchesItselfUnderWsl(): void
    {
        // Under WSL the lookup is this class's, on the PATH it was made with; elsewhere it is
        // Symfony's, which reads the PATH when it looks. A PATH emptied in between tells them apart.
        $tool = $this->program('bin/tool');
        \putenv('PATH='.$this->dir('bin'));
        \putenv('WSL_DISTRO_NAME=Ubuntu');
        $wsl = PathExecutables::here();
        \putenv('WSL_DISTRO_NAME');
        $elsewhere = PathExecutables::here();
        \putenv('PATH=');

        $this->assertSame($tool, $wsl->find('tool'));
        $this->assertNull($elsewhere->find('tool'));
    }

    public function testHereUnderWslWithoutAPathFindsNothing(): void
    {
        \putenv('WSL_DISTRO_NAME=Ubuntu');
        \putenv('PATH');

        $this->assertNull(PathExecutables::here()->find('sh'));
    }
}
