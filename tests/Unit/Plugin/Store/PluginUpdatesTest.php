<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Store;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Store\PackagistMetadata;
use Porta\Plugin\Store\PluginUpdate;
use Porta\Plugin\Store\PluginUpdates;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StoreFixture;
use Revolt\EventLoop;

/** Which installed plugins have a newer release, the background check, and updating them. */
final class PluginUpdatesTest extends TestCase
{
    use PumpsLoop;

    private StoreFixture $store;

    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->store = new StoreFixture();
    }

    /** @param array<string, bool|int|string> $settings */
    private function updates(array $settings = []): PluginUpdates
    {
        return $this->store->updates($settings, fn (): int => $this->now);
    }

    /** @return list<string> */
    private static function names(PluginUpdates $updates): array
    {
        return \array_map(static fn (PluginUpdate $update): string => $update->name.' '.$update->from.' → '.$update->to, $updates->outdated());
    }

    public function testOnlyAPackagistInstallWithANewerReleaseThatRunsHereIsOutdated(): void
    {
        $this->store->publish('acme/porta-old', 'v1.1.0');
        $this->store->publish('acme/porta-current', 'v1.0.0');
        $this->store->publish('acme/porta-path', 'v2.0.0');
        $this->store->publish('acme/porta-future', 'v3.0.0', api: 99);
        $this->store->fill();
        $this->store->installed('acme/porta-old', 'v1.0.0');
        $this->store->installed('acme/porta-current', 'v1.0.0', InstallScope::Project);
        $this->store->installed('acme/porta-path', 'v1.0.0', InstallScope::Project, fromPackagist: false);
        $this->store->installed('acme/porta-future', 'v1.0.0');
        $this->store->installed('acme/porta-unlisted', 'v1.0.0');
        $updates = $this->updates();

        $this->assertSame(['old v1.0.0 → v1.1.0'], self::names($updates));
        $this->assertSame(InstallScope::Global, $updates->outdated()[0]->scope);
        $this->assertSame('acme/porta-old', $updates->outdated()[0]->package);
    }

    public function testUpdateBringsThePluginUpAndSaysARestartRunsIt(): void
    {
        $this->store->publish('acme/porta-old', 'v1.1.0');
        $this->store->fill();
        $this->store->installed('acme/porta-old', 'v1.0.0');
        $updates = $this->updates();
        $update = $updates->outdated()[0];

        $updates->update($update);
        $this->assertTrue($updates->isUpdating('old', InstallScope::Global));
        $this->assertSame('Updating old…', $this->store->toast());

        $this->await(static fn (): bool => !$updates->isUpdating('old', InstallScope::Global), what: 'the update');
        $this->assertSame('Updated old v1.1.0 — reloaded live', $this->store->toast());
        $this->assertSame([$this->store->home->path('.porta/plugins/old/porta.php')], $this->store->lifecycle->activated, 'the running copy is swapped for the new code');
        $this->assertSame(['old'], $this->store->lifecycle->unloaded, 'stopped while composer rewrote its files');
        $require = (array) \json_decode((string) \file_get_contents($this->store->home->path('.porta/composer.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('1.1.0', $require['require']['acme/porta-old'], 'exactly the release the store checked runs here: a new major is reachable, and nothing newer slips in');
        $this->assertGreaterThan(0, $this->store->lifecycle->refreshed, 'the store re-reads its buttons');
    }

    public function testUpdateAllRunsOneAtATimeAndAFailureIsSaid(): void
    {
        $this->store->publish('acme/porta-one', 'v1.1.0');
        $this->store->publish('acme/porta-two', 'v1.1.0');
        $this->store->fill();
        $this->store->installed('acme/porta-one', 'v1.0.0');
        $this->store->installed('acme/porta-two', 'v1.0.0', InstallScope::Project);
        // Untrusted project: its copy cannot be updated, and says why.
        $this->store->trusted = false;
        $updates = $this->updates();

        $updates->updateAll();
        $this->await(fn (): bool => \str_contains($this->store->toast(), 'trust'), what: 'both updates');
        $this->assertStringContainsString('composer runs code of the project\'s own', $this->store->toast());
    }

    public function testAnUpdateAlreadyRunningIsNotQueuedTwice(): void
    {
        $this->store->publish('acme/porta-old', 'v1.1.0');
        $this->store->fill();
        $this->store->installed('acme/porta-old', 'v1.0.0');
        $updates = $this->updates();
        $update = $updates->outdated()[0];

        $updates->update($update);
        $updates->update($update);
        $this->await(static fn (): bool => !$updates->isUpdating('old', InstallScope::Global), what: 'the update');
        $this->assertStringContainsString('Updated old v1.1.0', $this->store->toast(), 'the second ask added nothing, and said nothing of its own');
    }

    public function testTheCheckIsDueWhenTheCatalogIsOlderThanTheCadence(): void
    {
        $this->store->publish('acme/porta-old', 'v1.1.0');
        $this->store->installed('acme/porta-old', 'v1.0.0');

        $this->updates(['pluginUpdateCheck' => 'off'])->checkIfDue();
        $this->assertSame([], $this->store->http->batches, 'off asks nothing');

        $updates = $this->updates(['pluginUpdateCheck' => 'nonsense']);
        $updates->checkIfDue();
        $this->assertCount(2, $this->store->http->batches, 'never fetched: due, at the daily default');
        $this->assertSame('Plugin updates available: old v1.1.0 — open Settings › Plugins › Store', $this->store->toast());

        $this->now = $this->store->catalog->fetchedAt() + 3600;
        $updates->checkIfDue();
        $this->assertCount(2, $this->store->http->batches, 'an hour on is not a day on');
        $updates->setCadence('daily');
        $this->assertCount(2, $this->store->http->batches);
        $this->now += 86_400;
        $updates->setCadence('bogus');
        $this->assertCount(4, $this->store->http->batches, 'an unknown cadence is the daily default');
        $this->now = $this->store->catalog->fetchedAt();
        $updates->setCadence('daily');
        $this->assertCount(4, $this->store->http->batches, 'a fresh check is not repeated');
    }

    public function testACheckThatFindsNothingOrCannotReachPackagistSaysNothing(): void
    {
        $this->store->publish('acme/porta-old', 'v1.0.0');
        $this->store->installed('acme/porta-old', 'v1.0.0');
        $this->updates()->checkIfDue();
        $this->assertSame('', $this->store->toast());

        $this->store->http->bodies = [];
        $this->now += 30 * 86_400;
        $this->updates()->checkIfDue();
        $this->assertSame('', $this->store->toast());
    }

    public function testAutoUpdateInstallsWhatTheCheckFinds(): void
    {
        $this->store->publish('acme/porta-old', 'v1.1.0');
        $this->store->installed('acme/porta-old', 'v1.0.0');
        $updates = $this->updates(['pluginAutoUpdate' => true]);

        $updates->checkIfDue();
        $this->await(fn (): bool => \str_starts_with($this->store->toast(), 'Updated'), what: 'the automatic update');

        $this->assertSame(
            '^1.0.0',
            \json_decode((string) \file_get_contents($this->store->home->path('.porta/composer.json')), associative: true, flags: \JSON_THROW_ON_ERROR)['require']['acme/porta-old'],
        );

        $updates->setAuto(false);
        $this->store->publish('acme/porta-old', 'v1.2.0');
        $this->now += 30 * 86_400;
        $updates->checkIfDue();
        $this->assertStringStartsWith('Plugin updates available', $this->store->toast(), 'switched off, it only says so');
    }

    public function testAutomaticUpdatesLeaveMajorAndZeroMinorUpgradesManual(): void
    {
        foreach (['major' => ['v1.0.0', 'v2.0.0'], 'zero' => ['v0.1.0', 'v0.2.0'], 'patch' => ['v0.1.0', 'v0.1.1']] as $name => [$from, $to]) {
            $this->store->publish('acme/porta-'.$name, $to);
            $this->store->installed('acme/porta-'.$name, $from);
        }
        $this->store->home->write('.porta/composer.json', '{"require":{"acme/porta-major":"*","acme/porta-zero":"*","acme/porta-patch":"*"}}');
        $updates = $this->updates(['pluginAutoUpdate' => true]);
        $updates->checkIfDue();
        $this->await(fn (): bool => \str_starts_with($this->store->toast(), 'Updated'), what: 'the compatible automatic update');
        $this->assertStringContainsString('patch v0.1.1', $this->store->toast());
        $this->assertStringNotContainsString('major', $this->store->toast());
        $this->assertStringNotContainsString('zero', $this->store->toast());
        $this->assertCount(3, $updates->outdated(), 'manual updates remain available');
    }

    public function testAutomaticSelectionFindsTheNewestCompatibleReleaseWithinTheExistingConstraint(): void
    {
        $package = 'acme/porta-constrained';
        $this->store->publish($package, 'v2.0.0');
        $url = \sprintf(PackagistMetadata::RELEASES, $package);
        $metadata = \json_decode($this->store->http->bodies[$url], associative: true, flags: \JSON_THROW_ON_ERROR);
        $release = $metadata['packages'][$package][0];
        foreach (['1.3.0', '1.2.0', '1.1.0'] as $version) {
            $metadata['packages'][$package][] = ['version' => 'v'.$version, 'version_normalized' => $version.'.0'] + $release;
        }
        $this->store->http->bodies[$url] = \json_encode($metadata, \JSON_THROW_ON_ERROR);
        $this->store->installed($package, 'v1.0.0');
        $this->store->home->write('.porta/composer.json', '{"require":{"acme/porta-constrained":">=1.0 <1.3"}}');
        $updates = $this->updates(['pluginAutoUpdate' => true]);
        $updates->checkIfDue();
        $this->await(fn (): bool => \str_starts_with($this->store->toast(), 'Updated'), what: 'the bounded update');
        $this->assertStringContainsString('constrained v1.2.0', $this->store->toast());
    }

    public function testAnUnparseableInstalledVersionDoesNotEscapeTheBackgroundCallback(): void
    {
        $this->store->publish('acme/porta-dev', 'v1.1.0');
        $this->store->installed('acme/porta-dev', 'dev-main');
        $updates = $this->updates(['pluginAutoUpdate' => true]);
        $updates->checkIfDue();
        $this->assertFalse($updates->isUpdating('dev', InstallScope::Global));
        $this->assertSame([], $this->store->lifecycle->activated);
    }

    public function testStartLooksAtOnceAndEveryHourUntilStopped(): void
    {
        $updates = $this->updates(['pluginUpdateCheck' => 'off']);
        $updates->start();
        $updates->start();
        $timer = Probe::get(Probe::object($updates, 'hourly'), 'timer');
        $this->assertIsString($timer);
        $this->assertTrue(EventLoop::isEnabled($timer));
        $updates->stop();
        $updates->stop();
        $this->assertNull(Probe::get(Probe::object($updates, 'hourly'), 'timer'));
    }

    public function testOnlyTheCopyThatRunsIsReloadedAndAnOffOneWaits(): void
    {
        $this->store->publish('acme/porta-off', 'v1.1.0');
        $this->store->publish('acme/porta-shadowed', 'v1.1.0');
        $this->store->fill();
        $this->store->installed('acme/porta-off', 'v1.0.0');
        $this->store->installed('acme/porta-shadowed', 'v1.0.0');
        // A project copy of the same name is the one that runs.
        $this->store->project->write('.porta/plugins/shadowed/porta.php', "<?php\n");
        $this->store->lifecycle->disabledNames = ['off'];
        $updates = $this->updates();

        $updates->updateAll();
        $this->await(fn (): bool => \str_starts_with($this->store->toast(), 'Updated'), what: 'both updates');
        $this->assertSame('Updated off v1.1.0, shadowed v1.1.0 — reloaded live', $this->store->toast());
        $this->assertSame([], $this->store->lifecycle->activated, 'switched off, or shadowed: nothing runs it to swap');
        $this->assertSame([], $this->store->lifecycle->unloaded, 'nor to stop');
    }

    /** @return iterable<string, array{0: string, 1?: string, 2?: bool}> */
    public static function loadedClassRoots(): iterable
    {
        yield 'plugin class' => ['acme/porta-classy/src/{class}.php'];
        yield 'shared dependency' => ['shared/library/{class}.php'];
        yield 'named entrypoint' => ['acme/porta-classy/porta.php'];
        yield 'named function' => ['acme/porta-classy/porta.php', 'function'];
        yield 'shared function' => ['shared/library/{class}.php', 'function'];
        yield 'disabled plugin with loaded dependency' => ['shared/library/{class}.php', 'class', true];
    }

    #[DataProvider('loadedClassRoots')]
    // @mago-expect lint:no-boolean-flag-parameter -- The provider varies the plugin enabled state independently of the symbol kind.
    public function testAPluginThatHadLoadedClassesAsksForARestartToFinish(string $classRoot, string $symbol = 'class', bool $disabled = false): void
    {
        $this->store->publish('acme/porta-classy', 'v1.1.0');
        $this->store->fill();
        $this->store->installed('acme/porta-classy', 'v1.0.0');
        $class = 'StaleStoreClass'.\bin2hex(\random_bytes(4));
        $declaration = 'function' === $symbol ? "function {$class}(): void {}" : "final class {$class} {}";
        $file = $this->store->home->write('.porta/vendor/'.\str_replace('{class}', $class, $classRoot), "<?php\n{$declaration}\n");
        if ($disabled) {
            $this->store->lifecycle->disabledNames = ['classy'];
        }
        require $file; // the old release's class, loaded before the update
        $restart = $this->store->restart();
        $updates = $this->store->updates(clock: fn (): int => $this->now, restart: $restart);

        $updates->updateAll();
        $this->await(fn (): bool => \str_starts_with($this->store->toast(), 'Updated'), what: 'the update');
        $this->assertSame('Updated classy v1.1.0 — restart scheduled for classy', $this->store->toast());
        $this->assertSame([], $this->store->lifecycle->activated, 'named-class plugins remain unloaded until the fresh process starts');
        $this->assertTrue(Probe::get($restart, 'pending'));
    }

    public function testAnAnonymousEntrypointStillReloadsLive(): void
    {
        $this->store->publish('acme/porta-anonymous', 'v1.1.0');
        $this->store->installed('acme/porta-anonymous', 'v1.0.0');
        $file = $this->store->home->write('.porta/vendor/acme/porta-anonymous/porta.php', '<?php return new class {};');
        $instance = require $file;
        $this->assertTrue(new \ReflectionClass($instance)->isAnonymous());
        $this->store->fill();
        $updates = $this->updates();
        $updates->updateAll();
        $this->await(fn (): bool => \str_starts_with($this->store->toast(), 'Updated'), what: 'the anonymous entrypoint update');
        $this->assertStringContainsString('reloaded live', $this->store->toast());
        $this->assertCount(1, $this->store->lifecycle->activated);
    }

    public function testAReloadThatFailsIsSaid(): void
    {
        $this->store->publish('acme/porta-old', 'v1.1.0');
        $this->store->fill();
        $this->store->installed('acme/porta-old', 'v1.0.0');
        $this->store->lifecycle->activateResult = 'Plugin "old" failed to load: boom';

        $this->updates()->updateAll();
        $this->await(fn (): bool => \str_contains($this->store->toast(), 'boom'), what: 'the failed reload');
        $this->assertSame('Plugin "old" failed to load: boom', $this->store->toast(), 'said as the failure it is, not as an update');
    }

    public function testAnUpdateComposerRefusesBringsThePluginBackOnItsOldFiles(): void
    {
        $this->store->installed('acme/porta-local', 'v1.0.0', InstallScope::Project);
        $this->store->publish('acme/porta-local', 'v1.1.0');
        $this->store->fill();
        $this->store->trusted = false; // composer may not run in the project
        $updates = $this->updates();

        $updates->updateAll();
        $this->await(fn (): bool => \str_contains($this->store->toast(), 'trust the project'), what: 'the refused update');
        $this->assertSame(['local'], $this->store->lifecycle->unloaded);
        $this->assertSame([$this->store->project->path('.porta/plugins/local/porta.php')], $this->store->lifecycle->activated, 'back on, from the files it still has');
    }

    public function testPartialFailureStillRestartsSuccessfullyUpdatedNamedClassPlugins(): void
    {
        $restart = $this->store->restart();
        $updates = $this->store->updates(restart: $restart);
        Probe::set($updates, 'restartFor', ['successful']);
        Probe::invoke($updates, 'report', ['successful v1.1.0'], ['other failed']);
        $this->assertTrue(Probe::get($restart, 'pending'));
        $this->assertStringContainsString('other failed', $this->store->toast());
    }

    public function testFailedNamedEntrypointUpdateRestartsWithoutRequiringItsClassAgain(): void
    {
        $this->store = new StoreFixture(composerExit: 1);
        $this->store->publish('acme/porta-failed', 'v1.1.0');
        $this->store->fill();
        $this->store->installed('acme/porta-failed', 'v1.0.0');
        $class = 'FailedEntrypoint'.\bin2hex(\random_bytes(6));
        $file = $this->store->home->write('.porta/vendor/acme/porta-failed/porta.php', '<?php final class '.$class.' {}');
        require $file;
        $restart = $this->store->restart();
        $updates = $this->store->updates(restart: $restart);
        $updates->updateAll();
        $this->await(fn (): bool => \str_contains($this->store->toast(), 'Cannot update'), what: 'the failed named-entrypoint update');
        $this->assertSame([], $this->store->lifecycle->activated);
        $this->assertTrue(Probe::get($restart, 'pending'));
    }

    public function testResumedSessionsDoNotRepeatFailedPluginUpdatesImmediately(): void
    {
        $this->store->publish('acme/porta-resumed', 'v1.1.0');
        $this->store->installed('acme/porta-resumed', 'v1.0.0');
        $updates = $this->store->updates(clock: fn (): int => $this->now, restartCheckAfter: (string) ($this->now + 86_400));
        $updates->checkIfDue();
        $this->assertSame([], $this->store->http->batches);
        $this->now += 86_400;
        $updates->checkIfDue();
        $this->assertCount(2, $this->store->http->batches);
    }
}
