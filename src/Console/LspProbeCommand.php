<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Config\IdeConfig;
use Porta\Lsp\LspClient;
use Porta\Lsp\LspClientInterface;
use Porta\Lsp\ServerLocator;
use Porta\Lsp\Uri;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Support\GitIgnored;
use Porta\Support\LanguageMap;
use Porta\Support\OrphanGuard;
use Porta\Support\Outcome;
use Porta\Support\ProcessReaper;
use Revolt\EventLoop;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Finder\Finder;

/**
 * The language-server instrument: start every server the IDE would start for
 * a project, the same way it would, show each the project's files, and report
 * everything that comes back that is not silence.
 *
 * The IDE's LSP log lives in memory and its diagnostics only for open files,
 * so a server misbehaving on a project was invisible from outside — and two
 * real defects hid there: symfony-lsp rejected the `shutdown` this client
 * sent (`params: null`, -32600), and phpantom was never told of a file
 * changed on disk. This makes the next one measurable. It reports, per
 * server: every line its client logged (stderr, an error reply, an
 * unanswered request, a death), each `window/logMessage` and
 * `window/showMessage` of error or warning type, and the diagnostics it
 * published last for each file it was shown. Any of them fails the run.
 *
 * Servers are resolved through the IDE's own chain — `.porta.json`, the
 * enabled plugins, {@see ServerLocator} — so the argv and
 * `initializationOptions` are the ones the editor sends. Plugins are
 * activated but never made ready: nothing a plugin starts on `onReady` (the
 * mcp socket) starts here.
 *
 * A server publishes in stages — phpantom sends an empty list at once and
 * the real one when its slow pass finishes, tens of seconds later on a big
 * file — so the probe listens for a fixed time and keeps the LAST set. A
 * file a server never finishes looks clean here, as it does in the editor:
 * for PHP, `phpantom_lsp analyze` (the other half of `composer lsp`) is the
 * deterministic per-file answer.
 */
#[AsCommand(
    name: 'lsp:probe',
    description: 'Run the project\'s language servers headless and report every diagnostic and server error',
    help: <<<'HELP'
        Starts each language server the IDE would start for <info>root</info> (default: the
        current directory), opens a sample of the files each one serves, listens, and
        reports what came back: client log lines (stderr, error replies, timeouts,
        deaths), error/warning log and show messages, and the last diagnostics published
        for each file. Exits 1 when there is anything to report.

          <info>lsp:probe</info>                           every server, 25 files per language, 20s
          <info>lsp:probe --only=symfony-lsp</info>        one server, by name
          <info>lsp:probe --per-language=0 --listen=60</info>  every file, a minute

        It starts real servers against the real project; run it on a copy when the
        server executes project code (symfony-lsp's runtime indexing).
        HELP,
    usages: ['--only=phpantom', '--per-language=0 --listen=60 /path/to/project'],
)]
final class LspProbeCommand extends Command
{
    use CommandErrorStream;

    /** Never walked: dependencies and tool state, as in {@see \Porta\Lsp\WatchedFiles}. */
    private const array NOT_WALKED = ['vendor', 'node_modules'];

    /** `MessageType` error and warning: the log and show messages that are findings. */
    private const int WARNING = 2;

    /** Seconds past a kill for the reaper's last poll to see the process gone. */
    private const float REAPED_AFTER = 0.2;

    /** `DiagnosticSeverity` names, 1-based. */
    private const array SEVERITY = [1 => 'error', 2 => 'warning', 3 => 'info', 4 => 'hint'];

    /** @var \Closure(string): array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>> */
    private readonly \Closure $servers;

    /** @var \Closure(list<string>, string, \Closure(string): void, array<string, mixed>|null): LspClientInterface */
    private readonly \Closure $clients;

    /**
     * @param (\Closure(string $root): array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>>)|null $servers
     *        the server map; null resolves it as the IDE does
     * @param (\Closure(list<string>, string, \Closure(string): void, array<string, mixed>|null): LspClientInterface)|null $clients
     *        the client factory; null spawns real, orphan-guarded servers
     */
    public function __construct(?\Closure $servers = null, ?\Closure $clients = null)
    {
        parent::__construct();
        $this->servers = $servers ?? self::resolve(...);
        $guard = new OrphanGuard();
        $this->clients =
            $clients ?? /**
             * @param list<string>              $command
             * @param array<string, mixed>|null $options
             */
            static fn (array $command, string $root, \Closure $log, ?array $options): LspClientInterface => new LspClient($command, $root, $log, initializationOptions: $options, guard: $guard);
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('The project root; the current directory when omitted')]
        string $root = '',
        #[Option('Only the server with this name (as the status bar names it)')]
        ?string $only = null,
        #[Option('Files opened per language, spread evenly over the sorted list; 0 opens every one')]
        int $perLanguage = 25,
        #[Option('Seconds to listen once the files are open')]
        float $listen = 20.0,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $resolved = \realpath('' === $root ? (string) \getcwd() : $root);
        $servers = false === $resolved || !\is_dir($resolved) ? null : self::byServer(($this->servers)($resolved), $only);
        if (false === $resolved || null === $servers || [] === $servers) {
            self::errorOutput($output)
                ->writeln(match (true) {
                    null === $servers => \sprintf('lsp:probe: no such directory: %s', $root),
                    null === $only => 'lsp:probe: no language server is configured for this project.',
                    default => \sprintf('lsp:probe: no server named %s.', $only),
                });

            return Command::INVALID;
        }
        $files = self::files($resolved, $perLanguage);

        $reports = [];
        foreach ($servers as $name => $server) {
            $opened = \array_merge(...\array_values(\array_intersect_key($files, \array_flip($server['languages']))));
            $io->writeln(\sprintf('<info>%s</info>: %s, %d file(s)', $name, \implode(' ', $server['command']), \count($opened)));
            $reports[$name] = $this->probe($resolved, $server, $opened);
        }
        EventLoop::delay($listen, static function () use ($reports): void {
            $processes = false;
            foreach ($reports as $report) {
                $processes = $processes || $report->client instanceof LspClient;
                $report->client?->stop();
            }
            // A stopped server has EXIT_WITHIN to go on its own, then the
            // reaper's GRACE before it is killed — and the reaper's timer
            // does not hold the loop. This does, so every server process is
            // reaped here rather than left for the orphan guard.
            if ($processes) {
                EventLoop::delay(LspClient::EXIT_WITHIN + ProcessReaper::GRACE + self::REAPED_AFTER, static function (): void {});
            }
        });
        EventLoop::run();

        return self::report($io, $resolved, $reports);
    }

    /**
     * The IDE's own resolution: the project's config, its enabled plugins
     * (activated, never made ready), then the locator.
     *
     * @return array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>>
     */
    private static function resolve(string $root): array
    {
        $host = new PluginHost(IdeConfig::load($root), new EventDispatcher(), $root);
        $host->ensureLoaded();

        return ServerLocator::locateAll($root, pluginServers: $host->manager->languageServers());
    }

    /**
     * One entry per server, with every language it serves — one process, as
     * the pool runs it, however many languages name it.
     *
     * @param array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>> $map
     *
     * @return array<string, array{command: list<string>, options: array<string, mixed>|null, languages: list<string>}>
     */
    private static function byServer(array $map, ?string $only): array
    {
        $servers = [];
        foreach ($map as $language => $entries) {
            foreach ($entries as $entry) {
                if (null !== $only && $only !== $entry['name']) {
                    continue;
                }
                $servers[$entry['name']] ??= ['command' => $entry['command'], 'options' => $entry['initializationOptions'] ?? null, 'languages' => []];
                $servers[$entry['name']]['languages'][] = $language;
            }
        }

        return $servers;
    }

    /**
     * The project's files by languageId: what git does not ignore, outside
     * the dependency trees, at most $perLanguage of each — spread evenly
     * over the sorted list so a sample is the same sample every run.
     *
     * @return array<string, list<string>> languageId => absolute paths
     */
    private static function files(string $root, int $perLanguage): array
    {
        $finder = Finder::create()
            ->files()
            ->in($root)
            ->exclude(self::NOT_WALKED)
            ->ignoreDotFiles(false)
            ->ignoreUnreadableDirs()
            ->ignoreVCSIgnored(true)
            ->filter(GitIgnored::pruner($root), prune: true)
            ->sortByName();
        $byLanguage = [];
        foreach ($finder as $file) {
            $path = $file->getPathname();
            $byLanguage[LanguageMap::lspForPath($path)][] = $path;
        }
        if ($perLanguage <= 0) {
            return $byLanguage;
        }

        return \array_map(static function (array $paths) use ($perLanguage): array {
            $count = \count($paths);
            if ($count <= $perLanguage) {
                return $paths;
            }

            return \array_map(static fn (int $k): string => $paths[\intdiv($k * $count, $perLanguage)], \range(0, $perLanguage - 1));
        }, $byLanguage);
    }

    /**
     * Start one server and wire every channel it can speak on into a report.
     *
     * @param array{command: list<string>, options: array<string, mixed>|null, languages: list<string>} $server
     * @param list<string>                                                                               $paths
     */
    private function probe(string $root, array $server, array $paths): LspProbeReport
    {
        $report = new LspProbeReport();
        $client = ($this->clients)($server['command'], $root, $report->log(...), $server['options']);
        $report->client = $client;
        $client->onNotification('textDocument/publishDiagnostics', $report->diagnostics(...));
        $client->onNotification('window/logMessage', $report->message(...));
        $client->onNotification('window/showMessage', $report->message(...));
        $client->start(static function (Outcome $outcome) use ($client, $report, $paths): void {
            if (!$outcome->isOk()) {
                $report->log('did not start');
                // Stopped here, not left to the deadline: a server that
                // rejected `initialize` may well still be running, and its
                // pipe watcher would hold the loop open for good.
                $client->stop();

                return;
            }
            foreach ($paths as $path) {
                $client->notify('textDocument/didOpen', ['textDocument' => [
                    'uri' => Uri::fromPath($path),
                    'languageId' => LanguageMap::lspForPath($path),
                    'version' => 1,
                    'text' => (string) \file_get_contents($path),
                ]]);
            }
        });

        return $report;
    }

    /** The project-relative path of $uri — or the whole of it, when it names nothing under the root. */
    private static function where(string $root, string $uri): string
    {
        $path = Uri::toPath($uri);

        return null !== $path && \str_starts_with($path, $root.'/') ? \substr($path, offset: \strlen($root) + 1) : $path ?? $uri;
    }

    /** @param array<string, LspProbeReport> $reports */
    private static function report(SymfonyStyle $io, string $root, array $reports): int
    {
        $findings = 0;
        foreach ($reports as $name => $report) {
            $rows = [];
            foreach ($report->logged as $line) {
                $rows[] = ['', 'log', $line];
            }
            foreach ($report->messages as [$type, $text]) {
                if ($type > self::WARNING) {
                    continue;
                }
                $rows[] = ['', 1 === $type ? 'error' : 'warning', $text];
            }
            foreach ($report->published as $uri => $diagnostics) {
                $path = self::where($root, $uri);
                foreach ($diagnostics as $diagnostic) {
                    $rows[] = [
                        $path.':'.($diagnostic['line'] + 1),
                        self::SEVERITY[$diagnostic['severity']] ?? 'error',
                        \trim($diagnostic['code'].' '.\explode("\n", $diagnostic['message'], limit: 2)[0]),
                    ];
                }
            }
            $io->section(\sprintf('%s — %d finding(s)', $name, \count($rows)));
            if ([] !== $rows) {
                $io->table(['where', 'kind', 'what'], $rows);
            }
            $findings += \count($rows);
        }
        if (0 === $findings) {
            $io->success('No diagnostics and no server errors.');

            return Command::SUCCESS;
        }
        $io->error(\sprintf('%d finding(s).', $findings));

        return Command::FAILURE;
    }
}
