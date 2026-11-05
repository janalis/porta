<?php

declare(strict_types=1);

namespace Porta\Xdebug\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\SettingsScope;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestPluginRuntime;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Xdebug\AdapterInstaller;
use Porta\Xdebug\AdapterLocator;
use Porta\Xdebug\AdapterSetup;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** The Install button: what it says, and that it runs one install at a time. */
final class AdapterSetupTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;
    use StatusReads;

    private const string FIXTURES = __DIR__.'/fixtures';

    private StatusBarWidget $status;

    protected function setUp(): void
    {
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // unzip
    }

    private function api(): PluginApi
    {
        $project = new TempProject();
        $scope = new SettingsScope(ConfigFiles::for($project->dir));
        $scope->set(ConfigScope::Project);
        $settings = new PluginSettings($project->dir, [], null, $scope);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $this->status = new StatusBarWidget();
        $manager->wireRuntime(TestPluginRuntime::create(new CodeEditorWidget(), $this->status));
        $api = $manager->api('xdebug', $project->dir, $settings);
        $manager->commit($api);

        return $api;
    }

    private function adapterSetup(PluginApi $api, ?string $home, string $url): AdapterSetup
    {
        return new AdapterSetup(
            $api,
            static fn (): AdapterLocator => new AdapterLocator($home, ''),
            static fn (string $target): AdapterInstaller => new AdapterInstaller(
                [\PHP_BINARY, self::FIXTURES.'/fetch.php'],
                $url,
                (string) \hash_file('sha256', self::FIXTURES.'/php-debug.vsix'),
                $target,
            ),
        );
    }

    public function testAnInstallSaysItIsUnderWayThenThatItLanded(): void
    {
        $api = $this->api();
        $home = new TempProject();
        $setup = $this->adapterSetup($api, $home->dir, 'slow:'.self::FIXTURES.'/php-debug.vsix');

        $setup->install();
        $this->assertSame('Installing the php-debug adapter 1.40.2…', $this->flashText($this->status));
        $setup->install();
        $this->assertSame('The php-debug adapter is already being installed', $this->flashText($this->status), 'a second press while the first runs');

        $status = $this->status;
        $this->await(fn (): bool => \str_contains($this->flashText($status), 'installed:'), what: 'the install to land');
        $this->assertSame('php-debug 1.40.2 installed: Shift+F9 debugs the PHP file in front', $this->flashText($this->status));
        $this->assertFileExists($home->path('.porta/tools/php-debug/1.40.2/extension/out/phpDebug.js'));

        $setup->install();
        $this->assertSame('Installing the php-debug adapter 1.40.2…', $this->flashText($this->status), 'and once it has, it may run again — the button is also the repair');
        $this->await(fn (): bool => \str_contains($this->flashText($status), 'installed:'), what: 'the reinstall to land');
    }

    public function testAFailureSaysWhy(): void
    {
        $api = $this->api();
        $home = new TempProject();
        $setup = $this->adapterSetup($api, $home->dir, 'fail');

        $setup->install();
        $status = $this->status;
        $this->await(fn (): bool => \str_contains($this->flashText($status), 'not installed'), what: 'the failure');
        $this->assertSame('The php-debug adapter was not installed: '.\PHP_BINARY.' exited with code 22', $this->flashText($this->status));

        $setup->install();
        $this->assertSame('Installing the php-debug adapter 1.40.2…', $this->flashText($this->status), 'a failure frees the button');
        $this->await(fn (): bool => \str_contains($this->flashText($status), 'not installed'), what: 'the second failure');
    }

    public function testNoHomeNoInstall(): void
    {
        $api = $this->api();

        $this->adapterSetup($api, null, 'unused')->install();

        $this->assertSame('No home directory to install the php-debug adapter into', $this->flashText($this->status));
    }
}
