<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\History\RestartSnapshot;
use Porta\Install\IdeUpdates;
use Porta\Install\IdeUpdateState;
use Porta\Install\Installation;
use Porta\Install\InstallMethod;
use Porta\Tests\Support\StoreFixture;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Workbench\UpdateRestart;

final class IdeUpdatesTest extends TestCase
{
    private StoreFixture $store;
    private UpdateRestart $restart;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->store = new StoreFixture();
        $this->restart = new UpdateRestart($this->store->project->dir, new BufferManager(), new RestartSnapshot(), TestTui::create(), $this->store->notifier, $this->store->translator);
    }

    /** @param array<string, int|bool|string> $settings */
    private function updates(array $settings = [], InstallMethod $method = InstallMethod::Phar, ?string $restartCheckAfter = null, ?Installation $installation = null): IdeUpdates
    {
        return new IdeUpdates(
            $settings,
            $this->store->http->fetch(),
            new IdeUpdateState($this->store->home->path('ide-update.json')),
            $installation ?? new Installation($method, '/porta'),
            $this->restart,
            $this->store->notifier,
            $this->store->translator,
            fn (): int => $this->now,
            version: '1.0.0',
            restartCheckAfter: $restartCheckAfter,
        );
    }

    public function testOptInAndInstallationOwnershipControlAutomaticRestart(): void
    {
        $this->store->http->bodies[IdeUpdates::RELEASE] = '{"tag_name":"v1.1.0"}';
        $updates = $this->updates();
        $updates->checkIfDue();
        $this->assertFalse($this->restart->updatesCore());
        $this->assertStringContainsString('Porta v1.1.0 is available', $this->store->toast());
        $updates->checkIfDue();
        $this->assertCount(1, $this->store->http->batches);
        $updates->setAuto(true);
        $this->now += 86_400;
        $updates->checkIfDue();
        $this->assertTrue($this->restart->updatesCore());
    }

    public function testUnsupportedInstallsRemainManualAndCadencesApplyLive(): void
    {
        $this->store->http->bodies[IdeUpdates::RELEASE] = '{"tag_name":"v2.0.0"}';
        $updates = $this->updates(['ideAutoUpdate' => true, 'ideUpdateCheck' => 'off'], InstallMethod::Checkout);
        $updates->start();
        $this->assertSame([], $this->store->http->batches);
        $updates->setCadence('weekly');
        $this->assertCount(1, $this->store->http->batches);
        $this->assertFalse($this->restart->updatesCore());
        $this->now += 86_400;
        $updates->setCadence('bad');
        $this->assertCount(2, $this->store->http->batches, 'invalid choice falls back to daily');
        $updates->stop();
    }

    public function testOnlyNewStableReleasesAreOfferedAndConcurrentChecksCoalesce(): void
    {
        $updates = $this->updates(['ideUpdateCheck' => 'bad', 'ideAutoUpdate' => true]);
        $this->store->http->hold = true;
        $updates->checkIfDue();
        $updates->checkIfDue();
        $this->assertCount(1, $this->store->http->batches);
        $this->store->http->release();
        $this->store->http->hold = false;
        foreach (['no JSON', '{"tag_name":"v0.9.0"}', '{"tag_name":"v1.1.0-beta"}', '{"tag_name":"v1.1.0","prerelease":true}', '{"tag_name":"v1.1.0","draft":true}'] as $answer) {
            $this->now += 86_400;
            $this->store->http->bodies[IdeUpdates::RELEASE] = $answer;
            $updates->checkIfDue();
            $this->assertFalse($this->restart->updatesCore());
        }
        $updates->setAuto(false);
    }

    public function testResumedSessionsCannotImmediatelyRestartAgainEvenWithoutACadenceNote(): void
    {
        $this->store->http->bodies[IdeUpdates::RELEASE] = '{"tag_name":"v1.1.0"}';
        $updates = $this->updates(['ideAutoUpdate' => true], restartCheckAfter: (string) ($this->now + 86_400));
        $updates->checkIfDue();
        $this->assertSame([], $this->store->http->batches);
        $this->now += 86_400;
        $updates->checkIfDue();
        $this->assertTrue($this->restart->updatesCore());
    }

    public function testAnotherInstallationCanUseTheFreshCachedReleaseWithoutAnotherRequest(): void
    {
        $this->store->http->bodies[IdeUpdates::RELEASE] = '{"tag_name":"v1.1.0"}';
        $this->updates()->checkIfDue();
        $this->assertFalse($this->restart->updatesCore());
        $this->updates(['ideAutoUpdate' => true], InstallMethod::ComposerGlobal)->checkIfDue();
        $this->assertCount(1, $this->store->http->batches);
        $this->assertTrue($this->restart->updatesCore());
    }

    public function testADesktopInstallWaitsForItsOwnPackageThenUpdatesItself(): void
    {
        $bundle = new TempProject(['runtime/porta.phar' => 'OLD', 'runtime/desktop-package' => "porta-desktop-macos-arm64.dmg\n"]);
        $installation = new Installation(InstallMethod::Desktop, $bundle->path('runtime/porta.phar'));
        $this->store->http->bodies[IdeUpdates::RELEASE] = '{"tag_name":"v2.0.0","assets":[{"name":"porta.phar"}]}';

        $this->updates(['ideAutoUpdate' => true], installation: $installation)->checkIfDue();
        $this->assertFalse($this->restart->updatesCore(), 'the release is out, its disk image is not yet');

        $this->now += 86_400;
        $this->store->http->bodies[IdeUpdates::RELEASE] = '{"tag_name":"v2.0.0","assets":[{"name":"porta.phar"},{"name":"porta-desktop-macos-arm64.dmg"}]}';
        $this->updates(['ideAutoUpdate' => true], installation: $installation)->checkIfDue();
        $this->assertTrue($this->restart->updatesCore());
    }

    public function testCheckingNowAnswersEvenWhenThereIsNothingNew(): void
    {
        $this->store->http->bodies[IdeUpdates::RELEASE] = '{"tag_name":"v1.0.0"}';
        $updates = $this->updates(['ideUpdateCheck' => 'off']);

        $updates->checkNow();

        $this->assertCount(1, $this->store->http->batches, 'asked although checks are off');
        $this->assertStringContainsString('Porta 1.0.0 is up to date.', $this->store->toast());
    }

    public function testCheckingNowOffersANewerReleaseAgain(): void
    {
        $this->store->http->bodies[IdeUpdates::RELEASE] = '{"tag_name":"v1.1.0"}';
        $updates = $this->updates();
        $updates->checkIfDue();

        $updates->checkNow();

        $this->assertCount(2, $this->store->http->batches);
        $this->assertStringContainsString('Porta v1.1.0 is available', $this->store->toast(), 'the offer is repeated for someone who asked');
    }

    public function testCheckingNowSaysSoWhenTheReleasesCannotBeRead(): void
    {
        $this->store->http->bodies[IdeUpdates::RELEASE] = 'not json';

        $this->updates()->checkNow();

        $this->assertStringContainsString('Could not check for updates.', $this->store->toast());
    }

    public function testASecondCheckWhileOneIsOutAsksNothingMore(): void
    {
        $this->store->http->hold = true;
        $updates = $this->updates();

        $updates->checkNow();
        $updates->checkNow();

        $this->assertStringContainsString('Checking for updates', $this->store->toast());
        $this->store->http->release();
        $this->assertCount(1, $this->store->http->batches);
    }
}
