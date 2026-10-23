<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\RemoteProjectPlugin;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRemoteTransports;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\FakeRemoteTransport;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** The first active plugin that answers for a server's scheme serves it. */
final class PluginRemoteTransportsTest extends TestCase
{
    private PluginManager $manager;

    protected function setUp(): void
    {
        $this->manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
    }

    /** @param \Closure(RemoteSpec): ?RemoteTransport $answer */
    private function add(string $name, \Closure $answer): void
    {
        $this->manager->commit($this->manager->api($name, '/p', new PluginSettings('/p', [])), new class($name, $answer) extends AbstractPlugin implements RemoteProjectPlugin {
            /** @param \Closure(RemoteSpec): ?RemoteTransport $answer */
            public function __construct(
                string $name,
                private readonly \Closure $answer,
            ) {
                parent::__construct($name);
            }

            public function activate(PluginApi $api): void {}

            public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
            {
                return ($this->answer)($spec);
            }
        });
    }

    public function testTheFirstPluginToAnswerServesTheProject(): void
    {
        $ftp = new FakeRemoteTransport('/ftp');
        $ssh = new FakeRemoteTransport('/ssh');
        $plain = $this->manager->api('plain', '/p', new PluginSettings('/p', []));
        $this->manager->commit($plain, new class('plain') extends AbstractPlugin {
            public function activate(PluginApi $api): void {}
        });
        $this->add('ftp', static fn (RemoteSpec $spec): ?RemoteTransport => 'ftp' === $spec->scheme ? $ftp : null);
        $this->add('ssh', static fn (RemoteSpec $spec): ?RemoteTransport => 'ssh' === $spec->scheme ? $ssh : null);
        $this->add('also-ssh', static fn (): RemoteTransport => new FakeRemoteTransport('/late'));
        $transports = new PluginRemoteTransports($this->manager);

        $this->assertSame($ssh, $transports->remoteTransport(new RemoteSpec('ssh', 'h', '/')));
        $this->assertSame($ftp, $transports->remoteTransport(new RemoteSpec('ftp', 'h', '/')));
        $this->assertSame(['ftp', 'ssh', 'also-ssh'], \array_keys($this->manager->remoteProjectPlugins()), 'only the ones that open servers, in load order');
    }

    public function testOneThatThrowsIsReportedAndTheNextIsAsked(): void
    {
        $ssh = new FakeRemoteTransport('/ssh');
        $this->add('broken', static fn (): never => throw new \RuntimeException('no ssh binary'));
        $this->add('ssh', static fn (): RemoteTransport => $ssh);

        $this->assertSame($ssh, new PluginRemoteTransports($this->manager)->remoteTransport(new RemoteSpec('ssh', 'h', '/')));
        $this->assertSame(['%name%' => 'broken', '%message%' => 'no ssh binary'], $this->manager->warnings[0]->params ?? null);
    }

    public function testNoneMeansNoPluginServesIt(): void
    {
        $this->assertNull(new PluginRemoteTransports($this->manager)->remoteTransport(new RemoteSpec('ftps', 'h', '/')));
    }

    public function testADisabledPluginNoLongerServes(): void
    {
        $this->add('ssh', static fn (): RemoteTransport => new FakeRemoteTransport('/ssh'));

        $this->manager->deactivate('ssh', new CommandRegistry());

        $this->assertSame([], $this->manager->remoteProjectPlugins());
    }
}
