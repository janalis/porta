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
        $binary = $this->project->write('vendor/bin/mago', "#!/bin/sh\nexit 0\n");
        chmod($binary, 0o755);

        $this->assertTrue(new MagoDetector($this->project->path())->installed());
    }

    public function testAProjectWithoutVendorBinMagoIsNotInstalled(): void
    {
        $this->assertFalse(new MagoDetector($this->project->path())->installed());
    }

    /** A stray non-executable file is not a usable tool. */
    public function testANonExecutableFileDoesNotCount(): void
    {
        $binary = $this->project->write('vendor/bin/mago', 'not a binary');
        chmod($binary, 0o644);

        $this->assertFalse(new MagoDetector($this->project->path())->installed());
    }
}
