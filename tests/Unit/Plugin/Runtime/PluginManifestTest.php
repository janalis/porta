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

    /** A plugin whose composer.json is $json; $with names the extra directories to create beside it. */
    private function composerPlugin(TempProject $project, string $name, string $json, string ...$with): PluginManifest
    {
        $dir = $project->path('plugins/'.$name);
        mkdir($dir, permissions: 0o777, recursive: true);
        foreach ($with as $extra) {
            mkdir($dir.'/'.$extra, permissions: 0o777, recursive: true);
        }
        file_put_contents($dir.'/plugin.php', data: "<?php\n");
        file_put_contents($dir.'/composer.json', data: $json);

        return PluginManifest::beside($dir.'/plugin.php');
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

    public function testOnlyAnInstallABLERequirementMeansTheresSomethingToInstall(): void
    {
        // The discriminator is composer's own: every installable package is
        // `vendor/name`, and no platform requirement has a slash. Getting this
        // wrong declines every plugin whose composer.json says `php: >=8.4` —
        // which is the whole bundled set.
        $project = new TempProject();

        $this->assertFalse($this->composerPlugin($project, 'nothing', '{}')->needsInstall(), 'no require at all');
        $this->assertFalse($this->composerPlugin($project, 'empty', '{"require":{}}')->needsInstall());
        $this->assertFalse($this->composerPlugin($project, 'notanobject', '{"require":"nope"}')->needsInstall());
        $this->assertFalse(
            $this->composerPlugin($project, 'platform', '{"require":{"php":">=8.4","ext-gd":"*","composer-runtime-api":"^2"}}')->needsInstall(),
            'platform requirements install nothing',
        );
        $this->assertFalse(
            $this->composerPlugin($project, 'devonly', '{"require-dev":{"phpunit/phpunit":"^11"}}')->needsInstall(),
            'the installer runs --no-dev, so a dev requirement is a refusal nothing could satisfy',
        );

        $real = $this->composerPlugin($project, 'real', '{"require":{"php":">=8.4","vend/lib":"^1.0"}}');
        $this->assertSame(['vend/lib'], $real->packages, 'the platform half is not a package');
        $this->assertTrue($real->needsInstall());
        $this->assertFalse(
            $this->composerPlugin($project, 'vendored', '{"require":{"vend/lib":"^1.0"}}', 'vendor')->needsInstall(),
            'already installed',
        );
    }

    public function testADeclaredClassmapOrFilesAsksForADumpToo(): void
    {
        // Porta reads a plugin's own psr-4 and nothing else — classmap and
        // files exist only in composer's dumped maps, so declaring one without
        // a vendor/ means those classes would never resolve.
        $project = new TempProject();

        $this->assertTrue($this->composerPlugin($project, 'cm', '{"autoload":{"classmap":["lib/"]}}')->needsInstall());
        $this->assertTrue($this->composerPlugin($project, 'fi', '{"autoload":{"files":["helpers.php"]}}')->needsInstall());
        $this->assertFalse($this->composerPlugin($project, 'dumped', '{"autoload":{"classmap":["lib/"]}}', 'vendor')->needsInstall());
        $this->assertFalse($this->composerPlugin($project, 'psr4only', '{"autoload":{"psr-4":{"A\\\\":"src/"}}}')->needsInstall(), 'psr-4 needs no dump');
        $this->assertFalse($this->composerPlugin($project, 'emptycm', '{"autoload":{"classmap":[]}}')->needsInstall());
        $this->assertFalse($this->composerPlugin($project, 'noautoload', '{"autoload":"nope"}')->needsInstall());
    }

    public function testAMalformedComposerJsonAsksForNothing(): void
    {
        // Same doctrine as a malformed plugin.json: degrade, never decline. A
        // typo must not make a plugin vanish behind a message about installing.
        $project = new TempProject();

        $this->assertFalse($this->composerPlugin($project, 'junk', 'not json at all')->needsInstall());
        $this->assertFalse($this->composerPlugin($project, 'scalar', '"a string"')->needsInstall());
    }
}
