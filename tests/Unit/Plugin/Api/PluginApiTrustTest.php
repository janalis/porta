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
 * `projectTrusted()`: what a plugin gates a project-chosen program on — the
 * answer as it stands, so a project trusted mid-session is trusted from then on.
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

    public function testAnUntrustedProjectIsNotUntilItIsTrusted(): void
    {
        $this->untrustProjects();
        $project = new TempProject();
        $api = $this->api($project);

        $this->assertFalse($api->projectTrusted());

        WorkspaceTrust::fromEnvironment()->trust($project->dir);
        $this->assertTrue($api->projectTrusted(), 'the answer as it stands, not the first one');
    }
}
