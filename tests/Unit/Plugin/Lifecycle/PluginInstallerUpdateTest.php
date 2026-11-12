<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Lifecycle;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Lifecycle\PluginUpdateMode;
use Porta\Support\FileLock;
use Porta\Tests\Support\Probe;
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

    private function update(
        PluginInstaller $installer,
        string $name,
        InstallScope $scope = InstallScope::Global,
        ?string $version = 'v1.1.0',
        PluginUpdateMode $mode = PluginUpdateMode::Manual,
    ): ?string {
        $done = false;
        $result = null;
        $installer->update(
            $name,
            $scope,
            $version,
            static function (?string $error) use (&$done, &$result): void {
                $done = true;
                $result = $error;
            },
            $mode,
        );
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

        $this->assertNull($this->update($this->store->installer, 'one', version: null));
        $composer = \json_decode((string) \file_get_contents($this->store->home->path('.porta/composer.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('^1.0.0', $composer['require']['acme/porta-one']);
    }

    public function testAnotherWriterAndAnAlreadyAppliedTargetDoNotRewriteThePackage(): void
    {
        $this->store->installed('acme/porta-one', 'v2.0.0');
        $file = $this->store->home->write('.porta/vendor/acme/porta-one/porta.php', '<?php // preserved');
        $lock = new FileLock($this->store->home->path('.porta/.porta-update.lock'));
        $this->assertTrue($lock->acquire());
        try {
            $this->assertSame('Cannot update "one"', $this->update($this->store->installer, 'one', mode: PluginUpdateMode::Automatic));
        } finally {
            $lock->release();
        }
        $this->assertNull($this->update($this->store->installer, 'one', mode: PluginUpdateMode::Automatic));
        $this->assertSame('<?php // preserved', \file_get_contents($file));
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

    public function testMalformedComposerConstraintsAreNeverInvented(): void
    {
        $this->store->installed('acme/porta-one', 'v1.0.0');
        $this->store->home->write('.porta/composer.json', '{"require":{"acme/porta-one":true,"0":"junk"}}');
        $this->assertSame('', $this->store->installer->packages()[0]['constraint']);
    }

    public function testTheScopeLeaseRemainsHeldThroughDependencyInstallation(): void
    {
        $this->store->installed('acme/porta-one', 'v1.0.0');
        $this->store->home->write('bin/composer-recipe.json', \json_encode(
            ['acme/porta-one' => ['composerJson' => \json_encode(
                [
                    'name' => 'acme/porta-one',
                    'type' => 'porta-plugin',
                    'require' => ['acme/porta-test-library' => '^1.0'],
                ],
                \JSON_THROW_ON_ERROR,
            )]],
            \JSON_THROW_ON_ERROR,
        ));
        $script = (string) \file_get_contents($this->store->home->path('bin/composer'));
        $script = \str_replace(
            "if ('install' === \$cmd) {",
            replace: "if ('install' === \$cmd) { file_put_contents(__DIR__.'/dependency-started', '1'); while (!is_file(__DIR__.'/release')) { usleep(1000); }",
            subject: $script,
        );
        $this->store->home->write('bin/composer', $script);
        $marker = $this->store->home->path('bin/dependency-started');
        $finished = false;
        $error = null;
        $lease = new FileLock($this->store->home->path('.porta/.porta-update.lock'));
        try {
            $this->store->installer->update('one', InstallScope::Global, 'v1.1.0', static function (?string $failure) use (&$finished, &$error): void {
                $error = $failure;
                $finished = true;
            });
            $this->await(static function () use ($marker, &$finished): bool {
                return \is_file($marker) || $finished;
            }, what: 'the dependency install to begin');
            $this->assertFileExists($marker);
            $this->assertFalse($lease->acquire(), 'another writer cannot enter between package and dependency installation');
            $this->assertTrue($this->store->installer->busy());
        } finally {
            $this->store->home->write('bin/release', '1');
            $this->await(static function () use (&$finished): bool {
                return $finished;
            }, what: 'the dependency install to finish');
            $lease->release();
        }
        $this->assertNull($error);
        $this->assertFalse($this->store->installer->busy());
        $this->assertTrue($lease->acquire());
        $lease->release();
    }

    public function testAnOwnedComposerCommandReportsAContendingWriter(): void
    {
        $this->store->installed('acme/porta-one', 'v1.0.0');
        $lock = new FileLock($this->store->home->path('.porta/.porta-update.lock'));
        $this->assertTrue($lock->acquire());
        $code = null;
        try {
            $this->assertTrue(Probe::invoke($this->store->installer, 'startComposer', $this->store->home->path('.porta'), ['install'], static function (int $status) use (&$code): void {
                $code = $status;
            }));
            $this->assertSame(75, $code, 'a contending command never starts Composer');
            $this->assertFalse($this->store->installer->busy());
        } finally {
            $lock->release();
        }
    }

    public function testAnAlreadyAppliedCommandReleasesAnOwnedLease(): void
    {
        $this->store->installed('acme/porta-one', 'v2.0.0');
        $code = null;
        $this->assertTrue(Probe::invoke($this->store->installer, 'startComposer', $this->store->home->path('.porta'), ['update', 'acme/porta-one:1.1.0'], static function (int $status) use (
            &$code,
        ): void {
            $code = $status;
        }));
        $this->assertSame(0, $code);
        $lease = new FileLock($this->store->home->path('.porta/.porta-update.lock'));
        $this->assertTrue($lease->acquire());
        $lease->release();
    }
}
