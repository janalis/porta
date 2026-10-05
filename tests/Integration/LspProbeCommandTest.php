<?php

declare(strict_types=1);

namespace Porta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Porta\Console\LspProbeCommand;
use Porta\Lsp\LspClientInterface;
use Porta\Lsp\Uri;
use Porta\Support\Outcome;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class LspProbeCommandTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject([
            'src/A.php' => '<?php // a',
            'src/B.php' => '<?php // b',
            'src/C.php' => '<?php // c',
            'config/app.yaml' => 'a: 1',
            'vendor/acme/D.php' => '<?php // never opened: a dependency',
            'README.md' => '# served by nobody',
        ]);
    }

    /**
     * @param array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>> $servers
     */
    private function tester(array $servers, ?\Closure $clients = null): CommandTester
    {
        return new CommandTester(new LspProbeCommand(static fn (string $root): array => $servers, $clients));
    }

    private const array PHP_AND_YAML = [
        'php' => [['command' => ['php-ls'], 'name' => 'php-ls']],
        'yaml' => [['command' => ['php-ls'], 'name' => 'php-ls'], ['command' => ['yaml-ls'], 'name' => 'yaml-ls', 'initializationOptions' => ['strict' => true]]],
    ];

    public function testEveryChannelAServerSpeaksOnIsReportedAndFailsTheRun(): void
    {
        /** @var array<string, array{client: FakeLspClient, options: array<string, mixed>|null}> $made */
        $made = [];
        $uri = Uri::fromPath($this->project->path('src/A.php'));
        $tester = $this->tester(self::PHP_AND_YAML, static function (array $command, string $root, \Closure $log, ?array $options) use (&$made, $uri): LspClientInterface {
            $client = new FakeLspClient();
            $made[$command[0]] = ['client' => $client, 'options' => $options];
            if ('php-ls' === $command[0]) {
                // Once the files are open, the server speaks on every channel.
                EventLoop::defer(static function () use ($client, $log, $uri): void {
                    $log("stderr: boom\n");
                    $log('   ');
                    $diagnostics = $client->notificationHandlers['textDocument/publishDiagnostics'];
                    $diagnostics([
                        'uri' => $uri,
                        'diagnostics' => [
                            [
                                'range' => ['start' => ['line' => 4]],
                                'severity' => 2,
                                'code' => 'unknown_member',
                                'message' => "Method 'foo' not found\nwith a second line",
                            ],
                            'not a diagnostic',
                        ],
                    ]);
                    // A later empty publish clears an earlier one: the last word counts.
                    $diagnostics(['uri' => Uri::fromPath('/elsewhere/Gone.php'), 'diagnostics' => [['message' => 'stale']]]);
                    $diagnostics(['uri' => Uri::fromPath('/elsewhere/Gone.php'), 'diagnostics' => []]);
                    $diagnostics(['uri' => 42]);
                    $client->notificationHandlers['window/logMessage'](['type' => 1, 'message' => 'index failed']);
                    $client->notificationHandlers['window/logMessage'](['type' => 3, 'message' => 'Opened file: just info']);
                    $client->notificationHandlers['window/showMessage'](['type' => 2, 'message' => 'no composer.json']);
                    $client->notificationHandlers['window/showMessage'](['type' => 4]);
                });
            }

            return $client;
        });

        $exit = $tester->execute(['root' => $this->project->dir, '--listen' => 0.05]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('php-ls — 4 finding(s)', $display);
        $this->assertStringContainsString('stderr: boom', $display);
        $this->assertStringContainsString('src/A.php:5', $display);
        $this->assertStringContainsString("unknown_member Method 'foo' not found", $display, 'the code, then the first line only');
        $this->assertStringNotContainsString('with a second line', $display);
        $this->assertStringNotContainsString('stale', $display);
        $this->assertStringContainsString('index failed', $display);
        $this->assertStringContainsString('no composer.json', $display);
        $this->assertStringNotContainsString('just info', $display, 'info and log types are not findings');
        $this->assertStringContainsString('yaml-ls — 0 finding(s)', $display);
        $this->assertStringContainsString('4 finding(s).', $display);

        // One process per server however many languages name it, with the options it is launched with.
        $this->assertSame(['php-ls', 'yaml-ls'], \array_keys($made));
        $this->assertSame(['strict' => true], $made['yaml-ls']['options']);
        $this->assertSame(['app.yaml', 'A.php', 'B.php', 'C.php'], self::opened($made['php-ls']['client']), 'what it serves, in path order, vendor/ left out');
        $this->assertSame(['app.yaml'], self::opened($made['yaml-ls']['client']));
        $this->assertTrue($made['php-ls']['client']->stopped, 'every server is stopped once the time is up');
    }

    public function testASilentServerIsASuccess(): void
    {
        $tester = $this->tester(self::PHP_AND_YAML, static fn (): LspClientInterface => new FakeLspClient());

        $this->assertSame(Command::SUCCESS, $tester->execute(['root' => $this->project->dir, '--listen' => 0.01]));
        $this->assertStringContainsString('No diagnostics and no server errors.', $tester->getDisplay());
    }

    public function testASampleIsSpreadOverTheSortedFilesAndZeroOpensThemAll(): void
    {
        $made = [];
        $clients = static function () use (&$made): LspClientInterface {
            return $made[] = new FakeLspClient();
        };

        $this->tester(['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]], $clients)->execute(['root' => $this->project->dir, '--listen' => 0.01, '--per-language' => 2]);
        $this->assertSame(['A.php', 'B.php'], self::opened($made[0]), 'indices 0 and 3/2: evenly spread, the same every run');

        $this->tester(['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]], $clients)->execute(['root' => $this->project->dir, '--listen' => 0.01, '--per-language' => 0]);
        $this->assertSame(['A.php', 'B.php', 'C.php'], self::opened($made[1]));
    }

    /** @return list<string> the basenames of the files $client was told were opened, in order */
    private static function opened(FakeLspClient $client): array
    {
        $opened = [];
        foreach ($client->notifications as $notification) {
            if ('textDocument/didOpen' !== $notification['method']) {
                continue;
            }
            /** @var array{textDocument: array{uri: string}} $params */
            $params = $notification['params'];
            $opened[] = \basename((string) Uri::toPath($params['textDocument']['uri']));
        }

        return $opened;
    }

    public function testOnlyNarrowsToOneServerByName(): void
    {
        $made = [];
        $tester = $this->tester(self::PHP_AND_YAML, static function (array $command) use (&$made): LspClientInterface {
            $made[] = $command[0];

            return new FakeLspClient();
        });

        $tester->execute(['root' => $this->project->dir, '--listen' => 0.01, '--only' => 'yaml-ls']);
        $this->assertSame(['yaml-ls'], $made);
    }

    public function testAServerThatDoesNotStartIsAFinding(): void
    {
        $tester = $this->tester(['php' => [['command' => [\PHP_BINARY, __DIR__.'/../Support/crashing-lsp-server.php'], 'name' => 'crashing']]]);

        $this->assertSame(Command::FAILURE, $tester->execute(['root' => $this->project->dir, '--listen' => 3.0]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('boom: refusing to speak LSP', $display, 'its stderr');
        $this->assertStringContainsString('did not start', $display);
    }

    public function testAServerThatRejectsInitializeAndStaysUpDoesNotHoldTheRunOpen(): void
    {
        // It answers `initialize` with an error and keeps reading: left
        // running, its pipe watcher held the loop open and the probe never returned.
        $tester = $this->tester(['php' => [['command' => [\PHP_BINARY, __DIR__.'/../Support/error-lsp-server.php'], 'name' => 'refusing']]]);

        $this->assertSame(Command::FAILURE, $tester->execute(['root' => $this->project->dir, '--listen' => 0.5]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('initialize failed', $display);
        $this->assertStringContainsString('did not start', $display);
    }

    public function testADiagnosticOutsideTheProjectNamesItsWholePath(): void
    {
        $tester = $this->tester(['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]], static function (): LspClientInterface {
            $client = new FakeLspClient();
            EventLoop::defer(static function () use ($client): void {
                $client->notificationHandlers['textDocument/publishDiagnostics'](['uri' => 'file:///elsewhere/Stub.php', 'diagnostics' => [['message' => 'from a stub']]]);
                $client->notificationHandlers['textDocument/publishDiagnostics'](['uri' => 'untitled:Scratch', 'diagnostics' => [['message' => 'from nowhere']]]);
            });

            return $client;
        });

        $tester->execute(['root' => $this->project->dir, '--listen' => 0.05]);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('/elsewhere/Stub.php:1', $display);
        $this->assertStringContainsString('untitled:Scratch:1', $display);
    }

    public function testARealServersDiagnosticsAreReportedEndToEnd(): void
    {
        $tester = $this->tester(['php' => [['command' => [\PHP_BINARY, __DIR__.'/../Support/mock-lsp-server.php'], 'name' => 'mock']]]);

        $this->assertSame(Command::FAILURE, $tester->execute(['root' => $this->project->dir, '--listen' => 3.0]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('mock — 3 finding(s)', $display, 'one per opened file — the empty publish for a file never opened is no finding');
        $this->assertStringContainsString('src/B.php:1', $display);
        $this->assertStringContainsString('Mock diagnostic', $display);
    }

    public function testTheServersAreTheOnesTheIdeWouldStart(): void
    {
        $home = new TempProject(['.porta/config.json' => \json_encode(['lsp' => ['php' => \PHP_BINARY.' '.__DIR__.'/../Support/mock-lsp-server.php']], \JSON_THROW_ON_ERROR)]);
        $this->project->write('.porta.json', \json_encode(['enabledPlugins' => []], \JSON_THROW_ON_ERROR));
        $previous = \getenv('PORTA_HOME');
        \putenv('PORTA_HOME='.$home->dir);
        try {
            $tester = new CommandTester(new LspProbeCommand());
            $tester->execute(['root' => $this->project->dir, '--listen' => 3.0, '--only' => 'mock-lsp-server']);
        } finally {
            \putenv(false === $previous ? 'PORTA_HOME' : 'PORTA_HOME='.$previous);
        }

        $this->assertStringContainsString('mock-lsp-server — 3 finding(s)', $tester->getDisplay());
    }

    public function testARootThatIsNoDirectoryOrServesNothingIsInvalid(): void
    {
        $tester = $this->tester(self::PHP_AND_YAML);
        $this->assertSame(Command::INVALID, $tester->execute(['root' => $this->project->path('nowhere')]));
        $this->assertStringContainsString('no such directory', $tester->getDisplay());

        $this->assertSame(Command::INVALID, $tester->execute(['root' => $this->project->dir, '--only' => 'nobody']));
        $this->assertStringContainsString('no server named nobody', $tester->getDisplay());

        $none = $this->tester([]);
        $this->assertSame(Command::INVALID, $none->execute(['root' => $this->project->dir]));
        $this->assertStringContainsString('no language server is configured', $none->getDisplay());
    }

    public function testTheCurrentDirectoryIsTheDefaultRoot(): void
    {
        $roots = [];
        $tester = new CommandTester(new LspProbeCommand(static function (string $root) use (&$roots): array {
            $roots[] = $root;

            return [];
        }));
        $tester->execute([]);

        $this->assertSame([(string) \realpath((string) \getcwd())], $roots);
    }

    public function testAStartThatFailsWithoutAProcessIsStillAFinding(): void
    {
        $tester = $this->tester(self::PHP_AND_YAML, static function (): LspClientInterface {
            $client = new FakeLspClient();
            $client->startOutcome = Outcome::Failed;

            return $client;
        });

        $this->assertSame(Command::FAILURE, $tester->execute(['root' => $this->project->dir, '--listen' => 0.01]));
        $this->assertStringContainsString('did not start', $tester->getDisplay());
    }
}
