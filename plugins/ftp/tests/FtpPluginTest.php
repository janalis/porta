<?php

declare(strict_types=1);

namespace Porta\FtpPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteSpec;
use Porta\FtpPlugin\Credentials;
use Porta\FtpPlugin\FtpTransport;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\RemoteProjectPlugin;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** The plugin serves ftp:// and ftps:// projects with the password `porta ftp` kept. */
final class FtpPluginTest extends TestCase
{
    use PreservesPortaHome;

    private function plugin(): RemoteProjectPlugin
    {
        $settings = new PluginSettings('/p', ['ftp' => ['binary' => '/opt/bin/curl']]);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $api = new PluginApi('ftp', $manager, '/p', $settings);
        $plugin = require \dirname(__DIR__).'/plugin.php';
        $this->assertInstanceOf(RemoteProjectPlugin::class, $plugin, 'the ftp plugin opens remote projects');
        $plugin->activate($api);
        $manager->commit($api, $plugin);

        return $plugin;
    }

    public function testItServesFtpWithTheKeptPassword(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $spec = new RemoteSpec('ftp', 'example.org', '/site', 'me');
        $netrc = (string) Credentials::store($home->dir, $spec, 'secret');
        $plugin = $this->plugin();

        $transport = $plugin->remoteTransport($spec);

        $this->assertInstanceOf(FtpTransport::class, $transport);
        $this->assertSame(['/opt/bin/curl', '--silent', '--show-error', '--netrc-file', $netrc, '--', 'u'], $transport->command([], 'u'));
        $anonymous = $plugin->remoteTransport(new RemoteSpec('ftps', 'example.org', '/'));
        $this->assertInstanceOf(FtpTransport::class, $anonymous);
        $this->assertNotContains('--netrc-file', $anonymous->command([], 'u'));
        $this->assertNull($plugin->remoteTransport(new RemoteSpec('ssh', 'example.org', '/')), 'not its scheme');
    }

    public function testWithoutAHomeThereIsNoPasswordToRead(): void
    {
        \putenv('PORTA_HOME');
        $home = \getenv('HOME');
        \putenv('HOME');
        try {
            $this->assertNull($this->plugin()->remoteTransport(new RemoteSpec('ftp', 'example.org', '/')));
        } finally {
            \putenv('HOME='.$home);
        }
    }
}
