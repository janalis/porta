<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Config\WorkspaceTrust;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\SettingsScope;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UntrustsProjects;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * `projectTrusted()`: what a plugin gates a project-chosen program on, asked
 * once — a window that re-answers mid-session does not change what this one
 * already decided.
 */
final class PluginApiTrustTest extends TestCase
{
    use UntrustsProjects;

    private function api(TempProject $project): PluginApi
    {
        $scope = new SettingsScope(ConfigFiles::for($project->dir));
        $scope->set(ConfigScope::Project);

        return new PluginManager(new EventDispatcher(), new PluginSettings($project->dir, [], null, $scope))
            ->api('demo', $project->dir, new PluginSettings($project->dir, [], null, $scope));
    }

    public function testAProjectTheSuiteTrustsIsTrusted(): void
    {
        $project = new TempProject();

        $this->assertTrue($this->api($project)->projectTrusted());
    }

    public function testAnUntrustedProjectIsNotAndTheAnswerIsKeptOnceAsked(): void
    {
        $this->untrustProjects();
        $project = new TempProject();
        $api = $this->api($project);

        $this->assertFalse($api->projectTrusted());

        WorkspaceTrust::fromEnvironment()->trust($project->dir);
        $this->assertTrue(WorkspaceTrust::fromEnvironment()->isTrusted($project->dir), 'the answer really changed on disk');
        $this->assertFalse($api->projectTrusted(), 'asked once per session, like the rest of what decides what a project may run');
        $this->assertTrue($this->api($project)->projectTrusted(), 'a new session reads the new answer');
    }
}
