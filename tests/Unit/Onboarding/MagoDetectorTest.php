<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Onboarding;

use PHPUnit\Framework\TestCase;
use Porta\Onboarding\MagoDetector;
use Porta\Tests\Support\TempProject;

/**
 * "Does this project ship mago?" is answered by the project's own vendor/bin
 * only — a machine-global mago must not flip the onboarding defaults.
 */
final class MagoDetectorTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    public function testAnExecutableVendorBinMagoCountsAsInstalled(): void
    {
        $binary = $this->project->write('vendor/bin/mago', content: "#!/bin/sh\nexit 0\n");
        \chmod($binary, permissions: 0o755);

        $this->assertTrue(new MagoDetector($this->project->path())->installed());
    }

    public function testAComposerBinDirElsewhereIsWhereMagoIs(): void
    {
        $this->project->write('composer.json', content: '{"config":{"bin-dir":"bin"}}');
        $binary = $this->project->write('vendor/bin/mago', content: "#!/bin/sh\nexit 0\n");
        \chmod($binary, permissions: 0o755);
        $this->assertFalse(new MagoDetector($this->project->path())->installed(), 'vendor/bin is not composer\'s here');

        \rename($binary, $this->project->write('bin/mago', content: ''));
        $this->assertTrue(new MagoDetector($this->project->path())->installed());
    }

    public function testAProjectWithoutVendorBinMagoIsNotInstalled(): void
    {
        $this->assertFalse(new MagoDetector($this->project->path())->installed());
    }

    /** A stray non-executable file is not a usable tool. */
    public function testANonExecutableFileDoesNotCount(): void
    {
        $binary = $this->project->write('vendor/bin/mago', content: 'not a binary');
        \chmod($binary, permissions: 0o644);

        $this->assertFalse(new MagoDetector($this->project->path())->installed());
    }
}
