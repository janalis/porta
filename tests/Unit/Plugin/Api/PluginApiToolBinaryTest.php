<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Plugin\Tool\ToolLocator;
use Porta\Plugin\Tool\ToolRequirement;
use Porta\Tests\Support\TempProject;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** A plugin finds its tool the way Settings does: the manifest's declaration first, its own shape otherwise. */
final class PluginApiToolBinaryTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    /** @param array<string, string> $settings the plugin's raw values */
    private function api(array $settings, ToolRequirement ...$declared): PluginApi
    {
        $store = new PluginSettings($this->project->dir, ['p' => $settings]);
        $locator = new ToolLocator($this->project->dir, $store->raw(...), static fn (): bool => true, null, static fn (): ?string => null);

        return new PluginApi('p', new PluginManager(new EventDispatcher(), $store), $this->project->dir, $store, null, null, $locator, $declared);
    }

    private function executable(string $relative): string
    {
        $path = $this->project->write($relative, "#!/bin/sh\n");
        \chmod($path, permissions: 0o755);

        return $path;
    }

    public function testADeclaredToolReadsTheSettingItsManifestNames(): void
    {
        $dot = $this->executable('custom/dot');
        $api = $this->api(['dotPath' => $dot, 'binary' => '/nowhere'], new ToolRequirement('p', 'dot', 'dot', setting: 'dotPath'));

        $this->assertSame($dot, $api->toolBinary('dot', 'binary'), 'the declaration\'s setting, not the caller\'s');
    }

    public function testAnUndeclaredToolTakesTheCallersShape(): void
    {
        $shim = $this->executable('vendor/bin/demo');
        $custom = $this->executable('custom/demo');

        $this->assertNull($this->api([])->toolBinary('demo'), 'PATH only by default');
        $this->assertSame($shim, $this->api([])->toolBinary('demo', lookup: BinarySearch::VendorBinThenPath));
        $this->assertSame($custom, $this->api(['binary' => $custom])->toolBinary('demo', 'binary'));
    }
}
