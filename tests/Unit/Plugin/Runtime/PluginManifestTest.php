<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Runtime\PluginManifest;
use Porta\Tests\Support\TempProject;

/** What a plugin declares about itself, and what it means when it declares nothing. */
final class PluginManifestTest extends TestCase
{
    /** A plugin directory with a src/ dir, and its manifest when $json is given. */
    private function pluginDir(TempProject $project, string $name, ?string $json): string
    {
        $dir = $project->path('plugins/'.$name);
        mkdir($dir.'/src', permissions: 0o777, recursive: true);

        return $this->entryFile($dir, $json);
    }

    /** The same, without the src/ dir a namespace would map to. */
    private function pluginDirWithoutSrc(TempProject $project, string $name, ?string $json): string
    {
        $dir = $project->path('plugins/'.$name);
        mkdir($dir, permissions: 0o777, recursive: true);

        return $this->entryFile($dir, $json);
    }

    private function entryFile(string $dir, ?string $json): string
    {
        file_put_contents($dir.'/plugin.php', data: "<?php\n");
        if (null !== $json) {
            file_put_contents($dir.'/plugin.json', data: $json);
        }

        return $dir.'/plugin.php';
    }

    public function testAPluginWithNoManifestShipsNothingAndRequiresNothing(): void
    {
        $project = new TempProject();
        $manifest = PluginManifest::beside($this->pluginDir($project, 'plain', null));

        $this->assertSame('plain', $manifest->name, 'the directory name is the identity');
        $this->assertNull($manifest->namespace);
        $this->assertNull($manifest->autoloadDir(), 'no namespace, nothing to autoload');
        $this->assertSame([], $manifest->requires);
    }

    public function testADeclaredNamespaceMapsToItsDirectory(): void
    {
        $project = new TempProject();
        $file = $this->pluginDir($project, 'acme', '{"namespace": "Acme\\\\Tools", "autoload": "src", "requires": ["image-viewer"]}');
        $manifest = PluginManifest::beside($file);

        $this->assertSame('Acme\\Tools', $manifest->namespace, 'stored without a leading separator');
        $this->assertSame(realpath($project->path('plugins/acme/src')), realpath((string) $manifest->autoloadDir()));
        $this->assertSame(['image-viewer'], $manifest->requires);
    }

    public function testAnAutoloadDirectoryThatDoesNotExistIsNotOne(): void
    {
        $project = new TempProject();
        $file = $this->pluginDirWithoutSrc($project, 'empty', '{"namespace": "Acme\\\\Empty"}');

        $this->assertNull(PluginManifest::beside($file)->autoloadDir());
    }

    public function testMalformedOrJunkMetadataFallsBackToTheDefaults(): void
    {
        // A plugin that worked yesterday must not vanish because its metadata
        // has a typo — and the failure is visible anyway: its classes will not
        // resolve.
        $project = new TempProject();
        foreach (['not json at all', '"a string"', '{"namespace": 7, "requires": "nope"}', '{"requires": [1, "", "ok"]}'] as $index => $json) {
            $manifest = PluginManifest::beside($this->pluginDir($project, 'junk'.$index, $json));
            $this->assertNull($manifest->namespace, $json);
            $this->assertSame('{"requires": [1, "", "ok"]}' === $json ? ['ok'] : [], $manifest->requires, $json);
        }
    }
}
