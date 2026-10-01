<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteSpec;
use Porta\Plugin\Api\OpenRemoteCommand;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/** What every `porta <scheme>` shares: read the location, let the plugin connect, open the cache. */
final class OpenRemoteCommandTest extends TestCase
{
    use PreservesPortaHome;

    private ?string $opened = null;
    /** @var list<RemoteSpec> */
    private array $connected = [];

    private function command(?string $home, ?string $refusal = null): CommandTester
    {
        $command = new class(
            function (string $root): int {
                $this->opened = $root;

                return 0;
            },
            $home,
            $refusal,
            $this->connected,
        ) extends OpenRemoteCommand {
            /** @param list<RemoteSpec> $connected */
            public function __construct(
                \Closure $launch,
                ?string $home,
                private readonly ?string $refusal,
                private array &$connected,
            ) {
                parent::__construct($launch, $home);
                $this->setName('demo');
            }

            protected function schemes(): array
            {
                return ['ftp', 'ftps'];
            }

            protected function connect(RemoteSpec $spec, InputInterface $input, OutputInterface $output): ?string
            {
                $this->connected[] = $spec;

                return $this->refusal;
            }
        };

        return new CommandTester($command);
    }

    public function testItConnectsThenOpensTheMarkedCache(): void
    {
        $home = new TempProject();
        $tester = $this->command($home->dir);

        $this->assertSame(0, $tester->execute(['location' => 'ftps://me@example.org/site']));

        $this->assertSame('ftps', $this->connected[0]->scheme);
        $this->assertSame('ftps://me@example.org/site', ProjectLocation::forRoot((string) $this->opened)->remote?->url());
        $this->assertStringStartsWith(\realpath($home->dir).'/.porta/remote/', (string) $this->opened);
        $this->assertFileExists($this->opened.'/.porta/onboarding.json');
        $this->assertStringContainsString('Opening me@example.org:/site', $tester->getDisplay());
    }

    public function testABareHostAndPathIsTheFirstScheme(): void
    {
        $home = new TempProject();
        $this->command($home->dir)->execute(['location' => 'example.org:/site']);

        $this->assertSame('ftp', $this->connected[0]->scheme);
    }

    public function testAnotherSchemeOrNoLocationIsRefused(): void
    {
        $tester = $this->command('/nonexistent');

        $this->assertSame(1, $tester->execute(['location' => 'ssh://example.org/']));
        $this->assertStringContainsString('Not a ftp/ftps location: ssh://example.org/', $tester->getDisplay());
        $this->assertSame([], $this->connected);
    }

    public function testARefusedConnectionOpensNothing(): void
    {
        $home = new TempProject();
        $tester = $this->command($home->dir, 'Cannot log in');

        $this->assertSame(1, $tester->execute(['location' => 'ftp://example.org/']));
        $this->assertStringContainsString('Cannot log in', $tester->getDisplay());
        $this->assertNull($this->opened);
    }

    public function testWithoutAHomeThereIsNowhereToKeepIt(): void
    {
        \putenv('PORTA_HOME');
        $home = \getenv('HOME');
        \putenv('HOME');
        try {
            $tester = $this->command(null);
            $this->assertSame(1, $tester->execute(['location' => 'ftp://example.org/']));
            $this->assertStringContainsString('No home directory to keep the project in', $tester->getDisplay());
        } finally {
            \putenv('HOME='.$home);
        }
    }
}
