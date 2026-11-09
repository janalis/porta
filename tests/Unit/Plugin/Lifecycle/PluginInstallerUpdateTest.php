<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Lifecycle;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StoreFixture;

/** What the store reads of an install — its package and version — and updating one. */
final class PluginInstallerUpdateTest extends TestCase
{
    use PumpsLoop;

    private StoreFixture $store;

    protected function setUp(): void
    {
        $this->store = new StoreFixture();
    }

    private function update(PluginInstaller $installer, string $name, InstallScope $scope = InstallScope::Global, ?string $version = 'v1.1.0'): ?string
    {
        $done = false;
        $result = null;
        $installer->update($name, $scope, $version, static function (?string $error) use (&$done, &$result): void {
            $done = true;
            $result = $error;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the update callback');

        return $result;
    }

    public function testPackagesListsWhatComposerInstalledAndWhereFrom(): void
    {
        $this->store->installed('acme/porta-one', 'v1.0.0');
        $this->store->installed('acme/porta-two', 'v2.0.0', InstallScope::Project, fromPackagist: false);
        $this->store->project->write('.porta/plugins/hand/porta.php', "<?php\n");
        $this->store->bundled('git');

        $packages = $this->store->installer->packages();
        $this->assertSame(
            [
                ['two', InstallScope::Project, 'acme/porta-two', 'v2.0.0', false],
                ['one', InstallScope::Global,  'acme/porta-one', 'v1.0.0', true],
            ],
            \array_map(static fn (array $copy): array => [$copy['name'], $copy['scope'], $copy['package'], $copy['version'], $copy['packagist']], $packages),
            'a hand-placed directory and a bundled plugin are no packages',
        );
        $this->assertStringEndsWith('/.porta/plugins/one', $packages[1]['dir']);
    }

    public function testAVersionComposerDidNotRecordIsUnknown(): void
    {
        $this->store->installed('acme/porta-one', 'v1.0.0');
        $this->store->home->write('.porta/vendor/composer/installed.json', '{"packages": [{"name": "acme/porta-one"}, "junk"]}');
        $this->assertSame('', $this->store->installer->packages()[0]['version']);

        $this->store->home->write('.porta/vendor/composer/installed.json', '{"packages": ["junk", {"name": "acme/porta-one", "version": "v1.0.1"}]}');
        $this->assertSame('v1.0.1', $this->store->installer->packages()[0]['version'], 'an unreadable entry is skipped, not the end of the list');
    }

    public function testAStoreInstallAsksComposerForExactlyTheReleaseItChecked(): void
    {
        $file = null;
        $this->store->installer->install(
            'acme/porta-new',
            InstallScope::Global,
            static function (?string $error, ?string $installed) use (&$file): void {
                $file = $installed ?? $error;
            },
            'v1.3.0',
        );
        $this->await(static function () use (&$file): bool {
            return null !== $file;
        }, what: 'the install');

        $composer = (array) \json_decode((string) \file_get_contents($this->store->home->path('.porta/composer.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('1.3.0', $composer['require']['acme/porta-new'], 'never the newest composer would pick, which may target another plugin API');
    }

    public function testUpdateWithNoVersionKeepsToTheConstraint(): void
    {
        $this->store->installed('acme/porta-one', 'v1.0.0');

        // The fake composer knows `require`, not `update`: its refusal is
        // what proves `update` is what ran.
        $this->assertSame('Cannot update "one"', $this->update($this->store->installer, 'one', version: null));
    }

    public function testUpdateRefusesWhatItCannotUpdate(): void
    {
        $this->store->installed('acme/porta-one', 'v1.0.0', InstallScope::Project);
        $this->store->project->write('.porta/plugins/hand/porta.php', "<?php\n");
        $installer = $this->store->installer;

        $this->assertSame('Built-in plugins update with Porta', $this->update($installer, 'git', InstallScope::Embedded));
        $this->assertSame('"hand" was not installed from a package, so Porta cannot update it', $this->update($installer, 'hand', InstallScope::Project));
        $this->assertSame('"nope" was not installed from a package, so Porta cannot update it', $this->update($installer, 'nope'));

        $this->store->trusted = false;
        $this->assertStringContainsString('trust the project', (string) $this->update($installer, 'one', InstallScope::Project));
    }

    public function testUpdateRefusesWhileAnotherRuns(): void
    {
        $this->store->installed('acme/porta-one', 'v1.0.0');
        $this->store->installed('acme/porta-two', 'v1.0.0', InstallScope::Project);
        $installer = $this->store->installer;
        $second = null;
        $first = false;
        $installer->update('one', InstallScope::Global, 'v1.1.0', static function () use (&$first): void {
            $first = true;
        });
        $installer->update('two', InstallScope::Project, 'v1.1.0', static function (?string $error) use (&$second): void {
            $second = $error;
        });

        $this->assertSame('Another install is already running', $second);
        $this->await(static function () use (&$first): bool {
            return $first;
        }, what: 'the first update');
    }

    public function testAFailingOrMissingComposerIsSaid(): void
    {
        $failing = new StoreFixture(composerExit: 1);
        $failing->installed('acme/porta-one', 'v1.0.0');
        $this->assertSame('Cannot update "one"', $this->update($failing->installer, 'one'));

        $this->store->installed('acme/porta-one', 'v1.0.0');
        $noComposer = new PluginInstaller($this->store->project->dir, $this->store->home->dir, new Translator('en'), null);
        $this->assertSame('composer is not available', $this->update($noComposer, 'one'));
    }

    public function testAScopeWhoseComposerProjectCannotBeWrittenIsSaid(): void
    {
        $this->store->installed('acme/porta-one', 'v1.0.0');
        // The scaffold is only written when missing: a directory in its place cannot be.
        \unlink($this->store->home->path('.porta/composer.json'));
        \mkdir($this->store->home->path('.porta/composer.json'));

        $this->assertSame('Cannot update "one"', $this->update($this->store->installer, 'one'));
    }
}
