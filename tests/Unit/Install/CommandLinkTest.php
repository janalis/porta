<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Porta\Install\CommandLink;
use Porta\Install\CommandLinkStatus;
use Porta\Tests\Support\TempProject;

/** The `porta` link, in a scratch home whose PATH is handed in — nothing global moves. */
final class CommandLinkTest extends TestCase
{
    private TempProject $home;

    private string $binary;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = new TempProject(['checkout/bin/porta' => "#!/usr/bin/env php\n", 'other/bin/porta' => "#!/usr/bin/env php\n"]);
        $this->binary = $this->home->path('checkout/bin/porta');
    }

    private function link(?string $path = null): CommandLink
    {
        return new CommandLink($this->binary, $path ?? $this->home->path('.local/bin'), $this->home->dir);
    }

    public function testNothingThereIsAbsentAndGoesIntoLocalBin(): void
    {
        $link = $this->link();

        $this->assertSame(CommandLinkStatus::Absent, $link->status());
        $this->assertSame($this->home->path('.local/bin/porta'), $link->target());
        $this->assertSame('', $link->pointsAt());
    }

    public function testLinkCreatesLocalBinAndPointsAtThisBinary(): void
    {
        $this->assertNull($this->link()->link());

        $this->assertSame($this->binary, \readlink($this->home->path('.local/bin/porta')));
        $this->assertSame(CommandLinkStatus::Current, $this->link()->status());
        $this->assertSame([], \array_filter((array) \glob($this->home->path('.local/bin/.porta.*'))), 'nothing staged is left behind');
    }

    public function testALinkToAnotherPortaIsStaleAndLinkingAgainRepointsIt(): void
    {
        \mkdir($this->home->path('.local/bin'), recursive: true);
        \symlink($this->home->path('other/bin/porta'), $this->home->path('.local/bin/porta'));

        $this->assertSame(CommandLinkStatus::Stale, $this->link()->status());
        $this->assertSame($this->home->path('other/bin/porta'), $this->link()->pointsAt());

        $this->assertNull($this->link()->link());
        $this->assertSame(CommandLinkStatus::Current, $this->link()->status());
    }

    public function testALinkLeftDanglingByAMovedCheckoutIsStale(): void
    {
        \mkdir($this->home->path('.local/bin'), recursive: true);
        \symlink('/gone/bin/porta', $this->home->path('.local/bin/porta'));

        $this->assertSame(CommandLinkStatus::Stale, $this->link()->status());
    }

    public function testAFileThatIsNotALinkIsForeignAndLeftAlone(): void
    {
        $this->home->write('.local/bin/porta', 'a copied phar');

        $this->assertSame(CommandLinkStatus::Foreign, $this->link()->status());
        $this->assertNotNull($this->link()->link());
        $this->assertNotNull($this->link()->unlink());
        $this->assertSame('a copied phar', \file_get_contents($this->home->path('.local/bin/porta')));
    }

    public function testThePortaAlreadyOnPathIsTheOneManaged(): void
    {
        \mkdir($this->home->path('bin'));
        \symlink($this->home->path('other/bin/porta'), $this->home->path('bin/porta'));
        $link = $this->link($this->home->path('nowhere').\PATH_SEPARATOR.$this->home->path('bin/').\PATH_SEPARATOR.$this->home->path('.local/bin'));

        $this->assertSame($this->home->path('bin/porta'), $link->target());
        $this->assertTrue($link->onPath());
        $link->link();
        $this->assertSame($this->binary, \readlink($this->home->path('bin/porta')));
        $this->assertFileDoesNotExist($this->home->path('.local/bin/porta'));
    }

    public function testALocalBinOffPathIsSaid(): void
    {
        $this->assertFalse($this->link('/usr/bin'.\PATH_SEPARATOR)->onPath());
        $this->assertTrue($this->link()->onPath());
    }

    public function testALinkOffPathIsStillFoundInLocalBin(): void
    {
        $this->link('/usr/bin')->link();

        $this->assertSame(CommandLinkStatus::Current, $this->link('/usr/bin')->status());
    }

    public function testUnlinkRemovesTheLinkOnly(): void
    {
        $this->link()->link();

        $this->assertNull($this->link()->unlink());

        $this->assertFalse(\is_link($this->home->path('.local/bin/porta')));
        $this->assertFileExists($this->binary);
        $this->assertSame(CommandLinkStatus::Absent, $this->link()->status());
    }

    public function testUnlinkWithNothingThereSaysSo(): void
    {
        $this->assertSame('there is no porta link to remove', $this->link()->unlink());
    }

    public function testADirectoryItCannotWriteIsReported(): void
    {
        $this->home->write('.local/keep', '');
        \chmod($this->home->path('.local'), permissions: 0o555);
        try {
            $this->assertStringContainsString('could not create', (string) $this->link()->link());
        } finally {
            \chmod($this->home->path('.local'), permissions: 0o755);
        }
    }

    public function testAReadOnlyBinIsReported(): void
    {
        \mkdir($this->home->path('.local/bin'), recursive: true);
        \chmod($this->home->path('.local/bin'), permissions: 0o555);
        try {
            $this->assertStringContainsString('could not write to', (string) $this->link()->link());
        } finally {
            \chmod($this->home->path('.local/bin'), permissions: 0o755);
        }
    }

    public function testALinkInAReadOnlyBinCannotBeRemoved(): void
    {
        $this->link()->link();
        \chmod($this->home->path('.local/bin'), permissions: 0o555);
        try {
            $this->assertStringContainsString('could not remove', (string) $this->link()->unlink());
        } finally {
            \chmod($this->home->path('.local/bin'), permissions: 0o755);
        }
    }
}
