<?php

declare(strict_types=1);

namespace Porta\FtpPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteSpec;
use Porta\FtpPlugin\Credentials;
use Porta\FtpPlugin\FtpTransport;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\RemoteProjectPlugin;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestPluginRuntime;
use Porta\Ui\CaretVisibility;
use Porta\Ui\TextField;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** The plugin serves ftp:// and ftps:// projects with the password `porta ftp` kept. */
final class FtpPluginTest extends TestCase
{
    use PreservesPortaHome;
    use StatusReads;

    private CodeEditorWidget $editor;
    private StatusBarWidget $status;

    private RemoteProjectPlugin $pluginInstance;

    private function plugin(): RemoteProjectPlugin
    {
        $settings = new PluginSettings('/p', ['ftp' => ['binary' => '/opt/bin/curl']]);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $this->editor = new CodeEditorWidget();
        $this->status = new StatusBarWidget();
        $manager->wireRuntime(TestPluginRuntime::create($this->editor, $this->status));
        $api = $manager->api('ftp', '/p', $settings);
        $plugin = require \dirname(__DIR__).'/plugin.php';
        $this->assertInstanceOf(RemoteProjectPlugin::class, $plugin, 'the ftp plugin opens remote projects');
        $this->assertInstanceOf(AbstractPlugin::class, $plugin, 'and is a plugin like any other');
        $plugin->activate($api);
        $manager->commit($api, $plugin);
        $this->pluginInstance = $plugin;

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
        $this->assertSame(
            ['/opt/bin/curl', '--silent', '--show-error', '--connect-timeout', '10', '--speed-limit', '1', '--speed-time', '30', '--netrc-file', $netrc, '--', 'u'],
            $transport->command([], 'u'),
        );
        $anonymous = $plugin->remoteTransport(new RemoteSpec('ftps', 'example.org', '/'));
        $this->assertInstanceOf(FtpTransport::class, $anonymous);
        $this->assertNotContains('--netrc-file', $anonymous->command([], 'u'));
        $this->assertNull($plugin->remoteTransport(new RemoteSpec('ssh', 'example.org', '/')), 'not its scheme');
    }

    /**
     * Opened from inside Porta there was no terminal to ask on: the server's
     * refusal asks, masked — once, not at every refusal — and keeps what is
     * typed where `porta ftp` would have.
     */
    public function testARefusedLoginAsksForThePasswordOnceAndKeepsIt(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $spec = new RemoteSpec('ftp', 'example.org', '/site', 'me');
        $transport = $this->plugin()->remoteTransport($spec);
        $this->assertInstanceOf(FtpTransport::class, $transport, 'its scheme');
        $refused = Probe::get($transport, 'refused');
        $this->assertInstanceOf(\Closure::class, $refused, 'the plugin listens for a refused login');
        $retried = 0;
        $transport->onRetry(static function () use (&$retried): void {
            ++$retried;
        });

        $refused();
        $this->assertTrue(EditorState::promptOpen($this->editor), 'asked');
        /** @var array{label: string, value: TextField} $prompt */
        $prompt = Probe::get(Probe::object($this->editor, 'overlayHost'), 'prompt');
        $this->assertSame('Password for me@example.org', $prompt['label']);
        foreach (\str_split('s3cret') as $key) {
            $this->editor->handleInput($key);
        }
        $this->assertSame('••••••', $prompt['value']->render(CaretVisibility::Hidden), 'never shown');
        $this->editor->handleInput("\r");

        $this->assertStringContainsString('password s3cret', (string) \file_get_contents(Credentials::file($home->dir, $spec)));
        $this->assertSame('Password kept — the server is asked again shortly', $this->flashText($this->status));
        $this->assertSame(1, $retried, 'and it is: now, not after the pause a refused login waits');

        $refused(); // the kept one was wrong: asked again
        $this->assertTrue(EditorState::promptOpen($this->editor));
        $this->editor->handleInput("\x1b"); // and dismissed
        $refused();
        $this->assertFalse(EditorState::promptOpen($this->editor), 'not at every refusal');

        Probe::set($this->pluginInstance, 'asked', (\hrtime(true) / 1e9) - 61.0);
        $refused();
        $this->assertTrue(EditorState::promptOpen($this->editor), 'but a minute on, a refusal is someone trying again');
    }

    public function testAnAnonymousLoginHasNothingToAsk(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $transport = $this->plugin()->remoteTransport(new RemoteSpec('ftp', 'example.org', '/'));
        $refused = Probe::get((object) $transport, 'refused');
        $this->assertInstanceOf(\Closure::class, $refused, 'the plugin listens for a refused login');

        $refused();

        $this->assertFalse(EditorState::promptOpen($this->editor));
    }

    public function testAPasswordThatCannotBeKeptSaysSo(): void
    {
        $home = new TempProject(['.porta' => 'a file, not a directory']);
        \putenv('PORTA_HOME='.$home->dir);
        $transport = $this->plugin()->remoteTransport(new RemoteSpec('ftp', 'example.org', '/', 'me'));
        $refused = Probe::get((object) $transport, 'refused');
        $this->assertInstanceOf(\Closure::class, $refused, 'the plugin listens for a refused login');

        $refused();
        $this->editor->handleInput('x');
        $this->editor->handleInput("\r");

        $this->assertStringContainsString('Cannot keep the password in', $this->flashText($this->status));
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
