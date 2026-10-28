<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Config\WorkspaceTrust;
use Porta\Container\Ansi;
use Porta\Container\LspMode;
use Porta\Container\ToolLaunch;
use Porta\Ide;
use Porta\Install\GitToolSetup;
use Porta\Support\BootTrace;
use Porta\Support\DiagnosticLog;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * The `porta` command: resolves the target path/options and launches the IDE.
 *
 * The IDE launch and the interactive-terminal check are injected so the command
 * logic is fully testable without a TTY or a real editor session.
 */
#[AsCommand(
    name: 'porta',
    description: 'Open the Porta terminal IDE for PHP projects',
    help: <<<'HELP'
        Open a PHP project (or a single file) in the Porta terminal IDE.

          <info>porta</info>                     open the current directory
          <info>porta src/</info>                open a directory
          <info>porta src/Ide.php:200</info>     open a file at line 200
          <info>porta --no-lsp .</info>          start without a language server
          <info>porta --git-setup</info>         make porta git's difftool and mergetool
          <info>porta --diff A B</info>          compare two files (what git difftool runs)
          <info>porta --merge L R B M</info>     resolve M in the merge view (what git mergetool runs;
                                   exits 1 while conflict markers remain in M)
          <info>porta --self-update</info>       replace this porta.phar with the latest release
          <info>porta review …</info>            write and read local code reviews (porta review --help)

        Keys: Ctrl+T/F2 switch tree/editor · Ctrl+S save · Ctrl+Space complete ·
        F12/Ctrl+G go to definition (Alt+Left back) · Ctrl+Z/Ctrl+R undo/redo · Ctrl+Q quit.

        Language server resolution: --lsp, then .porta.json {"lsp": …}, then $PORTA_LSP,
        then the enabled language-server plugins (phpantom for PHP; enable the phpactor
        plugin to use phpactor instead; symfony-lsp for Symfony projects — twig, yaml,
        xml, json and dotenv, plus PHP when neither PHP server is enabled), then
        typescript-language-server / pyright on PATH.
        HELP,
    usages: ['src/', 'src/Ide.php:200', '--no-lsp .', '--git-setup', '--diff LOCAL REMOTE', '--merge LOCAL REMOTE BASE MERGED', '--self-update'],
)]
final class PortaCommand extends Command
{
    use CommandErrorStream;

    /** @var callable(Workspace, ?string, LspMode, Ansi, \Closure): int */
    private $launch;

    /** @var callable(): bool */
    private $isInteractive;

    /** @var \Closure(list<string>): array{int, string} */
    private readonly \Closure $git;

    /** @var \Closure(list<string>, ?string): array{int, string} */
    private readonly \Closure $process;

    private readonly WorkspaceTrust $trust;

    /**
     * @param (callable(Workspace, ?string, LspMode, Ansi, \Closure): int)|null $launch        defaults to booting a real {@see Ide}, returning its exit code; the
     *                                                                                         trailing closure is called between boot and run, so a crash can say
     *                                                                                         which half died
     * @param (callable(): bool)|null                                           $isInteractive defaults to a POSIX tty check
     * @param (\Closure(list<string>): array{int, string})|null                $git           runs `git <args>` for --git-setup; defaults to the real binary
     * @param string                                                            $self          this binary's absolute path, the one git is told to run (bin/porta
     *                                                                                         passes the phar's or its own)
     * @param (\Closure(list<string>, ?string): array{int, string})|null         $process       runs `gh`/`curl`/the new phar for --self-update; defaults to real processes
     * @param string                                                            $version       the version this binary is (the phar's release tag)
     * @param string                                                            $phar          the phar running, '' when not one — only a phar can update itself
     * @param array<string, string|false>                                       $env           the environment, for a GitHub token
     * @param WorkspaceTrust|null                                               $trust         whose answers decide what a project may run; defaults to the user's
     */
    public function __construct(
        ?callable $launch = null,
        ?callable $isInteractive = null,
        ?\Closure $git = null,
        private readonly string $self = 'porta',
        ?\Closure $process = null,
        private readonly string $version = Ide::VERSION,
        private readonly string $phar = '',
        private readonly array $env = [],
        ?WorkspaceTrust $trust = null,
    ) {
        parent::__construct();
        $this->launch = $launch ?? self::launchIde(...);
        // stream_isatty is core PHP — the posix_isatty it replaced needed
        // ext-posix, and a box without it was refused as "not interactive".
        $this->isInteractive = $isInteractive ?? static fn (): bool => \stream_isatty(\STDIN) && \stream_isatty(\STDOUT);
        $this->git = $git ?? self::runGit(...);
        $this->process = $process ?? self::runProcess(...);
        $this->trust = $trust ?? WorkspaceTrust::fromEnvironment();
    }

    /** @param list<string> $path */
    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('File or directory to open (accepts the file:line shorthand); the two files of --diff, the four of --merge')]
        array $path = [],
        #[Option('Language server command (overrides .porta.json and $PORTA_LSP)')]
        ?string $lsp = null,
        #[Option('Start with no language server')]
        bool $noLsp = false,
        #[Option('Open the file scrolled to this line', shortcut: 'l')]
        ?string $line = null,
        #[Option('Compare two files, as git difftool runs it: --diff LOCAL REMOTE')]
        bool $diff = false,
        #[Option('Resolve a merge, as git mergetool runs it: --merge LOCAL REMOTE BASE MERGED')]
        bool $merge = false,
        #[Option('Make porta git\'s difftool and mergetool, in the global git config')]
        bool $gitSetup = false,
        #[Option('Replace this porta.phar with the latest release')]
        bool $selfUpdate = false,
        #[Option('With --git-setup: print the git config commands; with --self-update: only say what is available')]
        bool $dryRun = false,
    ): int {
        // Failures speak on stderr so a piped stdout stays clean (the
        // CoverageCheckCommand pattern); CommandTester's plain output has no
        // error channel and falls back to the one stream.
        $errors = self::errorOutput($output);
        $io = new SymfonyStyle($input, $errors);

        $problem = match (true) {
            null !== $lsp && $noLsp => '--lsp and --no-lsp contradict each other; pass one.',
            null !== $line && !\ctype_digit($line) => \sprintf('--line expects a line number, got "%s".', $line),
            default => self::argumentProblem(\count($path), $diff, $merge, $gitSetup || $selfUpdate, $dryRun, null !== $line, $gitSetup && $selfUpdate),
        };
        if (null !== $problem) {
            $io->error('porta: '.$problem);

            return Command::INVALID;
        }

        return match (true) {
            $gitSetup => $this->setUpGit($output, $io, $dryRun),
            $selfUpdate => $this->updateSelf($output, $io, $dryRun),
            default => $this->open($output, $errors, $io, $path, $line, $diff, $merge, $lsp, $noLsp),
        };
    }

    /**
     * An ordinary or a git tool launch: the workspace the arguments name,
     * opened in the IDE.
     *
     * @param list<string> $path
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the command's own flags, passed through from __invoke().
    private function open(
        OutputInterface $output,
        OutputInterface $errors,
        SymfonyStyle $io,
        array $path,
        ?string $line,
        bool $diff,
        bool $merge,
        ?string $lsp,
        bool $noLsp,
    ): int {
        $cwd = (string) \getcwd();
        try {
            $workspace = $diff || $merge ? self::toolWorkspace($cwd, $path, $merge) : self::editWorkspace($cwd, $path[0] ?? $cwd, $line);
        } catch (\InvalidArgumentException $e) {
            $io->error('porta: '.$e->getMessage());

            return Command::FAILURE;
        }

        return $this->launchInto($output, $errors, $io, $workspace, $lsp, $noLsp);
    }

    /** Boots the IDE on $workspace, once the terminal is known to be interactive. */
    // @mago-expect lint:no-boolean-flag-parameter -- --no-lsp, passed through from __invoke().
    private function launchInto(OutputInterface $output, OutputInterface $errors, SymfonyStyle $io, Workspace $workspace, ?string $lsp, bool $noLsp): int
    {
        if (!($this->isInteractive)()) {
            $io->error('porta: an interactive terminal is required.');

            return Command::FAILURE;
        }
        // Before the IDE takes the terminal: the answer decides what loads.
        // A git tool launch never asks — it only compares files, restricted.
        if (null === $workspace->tool && $this->trust->shouldAsk($workspace->rootDir)) {
            $this->askTrust($io, $workspace->rootDir);
        }
        // --ansi/--no-ansi are Symfony's global options; they resolve into the
        // output decoration, which we thread to the IDE as its color mode.
        $booted = false;
        try {
            $exitCode = ($this->launch)($workspace, $lsp, $noLsp ? LspMode::Disabled : LspMode::Enabled, $output->isDecorated() ? Ansi::Enabled : Ansi::Disabled, static function () use (
                &$booted,
            ): void {
                $booted = true;
            });
        } catch (\Throwable $e) {
            if ($output->isVerbose()) {
                throw $e; // -v: let the console runner render the full trace
            }
            $io->error(\sprintf('porta: fatal: %s', $e->getMessage()));
            // The likeliest BOOT failure is a container that no longer
            // compiles against a stale dump; say the remedy instead of
            // dumping a stack trace at someone who just typed `porta .`.
            match ($booted) {
                false => $errors->writeln('A stale container cache can break startup — delete ~/.cache/porta (or $PORTA_CACHE_DIR) and retry. Run with -v for the full trace.'),
                true => self::logCrash($errors, $workspace, $e),
            };
            $exitCode = Command::FAILURE;
        }

        return $exitCode;
    }

    /**
     * A crash mid-session has nothing to do with the cache. Keep the
     * evidence where the in-session error handler keeps its own — the
     * crash took the trace with it, and a -v rerun reproduces nothing.
     */
    private static function logCrash(OutputInterface $errors, Workspace $workspace, \Throwable $e): void
    {
        DiagnosticLog::forRoot($workspace->rootDir, 'error')->append(\sprintf("%s: %s\n%s", $e::class, $e->getMessage(), $e->getTraceAsString()));
        $errors->writeln('porta crashed — the trace was appended to .porta/error.log. Run with -v to see it here.');
    }

    /**
     * Ask, once per project, whether it may run code of its own — and keep the
     * answer either way. No is the default: restricted is what a stranger's
     * project should get from a stray Enter.
     */
    private function askTrust(SymfonyStyle $io, string $root): void
    {
        $io->writeln([
            \sprintf(
                '<comment>%s</comment> can run code of its own: its .porta/plugins, the binaries its .porta.json names, the tools in its vendor/bin, and whatever its config tells a language server to run.',
                $root,
            ),
            'Untrusted, it opens restricted: none of that runs, and neither do language servers, so there is no completion or diagnostics. You can change your mind from the palette (Trust This Project).',
        ]);
        $trusted = $io->confirm('Trust this project?', default: false);
        if (!($trusted ? $this->trust->trust($root) : $this->trust->restrict($root))) {
            $io->warning('porta: could not save the answer in ~/.porta/trusted-projects.json — you will be asked again.');
        }
    }

    /** Why this combination of paths and modes cannot run, or null when it can. */
    private static function argumentProblem(int $paths, bool $diff, bool $merge, bool $maintenance, bool $dryRun, bool $line, bool $bothMaintenance): ?string
    {
        return match (true) {
            $bothMaintenance || ((int) $diff + (int) $merge + (int) $maintenance) > 1 => '--diff, --merge, --git-setup and --self-update are separate modes; pass one.',
            $dryRun && !$maintenance => '--dry-run only goes with --git-setup or --self-update.',
            $maintenance && 0 !== $paths => '--git-setup and --self-update take no path.',
            $diff && 2 !== $paths => \sprintf('--diff compares two files (git difftool\'s $LOCAL $REMOTE); got %d.', $paths),
            $merge && 4 !== $paths => \sprintf('--merge takes four files (git mergetool\'s $LOCAL $REMOTE $BASE $MERGED); got %d.', $paths),
            ($diff || $merge) && $line => '--line opens a file for editing; it does not go with --diff or --merge.',
            !$diff && !$merge && $paths > 1 => 'one file or directory at a time — use --diff A B to compare two.',
            default => null,
        };
    }

    /** An ordinary launch: the target's workspace, --line overriding the file:line shorthand. */
    private static function editWorkspace(string $cwd, string $target, ?string $line): Workspace
    {
        $workspace = new WorkspaceResolver()->resolve($target, $cwd);
        $openAt = null !== $line ? \max(1, (int) $line) : $workspace->line;

        return new Workspace($workspace->rootDir, $workspace->initialFile, $openAt);
    }

    /**
     * A git tool launch: rooted where git runs it — the work-tree top, its
     * working directory — never at a temp blob's folder, with every path made
     * absolute against that directory (git hands them over relative).
     *
     * @param list<string> $paths
     */
    // @mago-expect lint:no-boolean-flag-parameter -- a diff or a merge is the whole choice, and the two call shapes differ only in the path count already checked.
    private static function toolWorkspace(string $cwd, array $paths, bool $merge): Workspace
    {
        $absolute = \array_map(static fn (string $path): string => \str_starts_with($path, '/') ? $path : $cwd.'/'.$path, $paths);
        $root = new WorkspaceResolver()->resolve($cwd, $cwd)->rootDir;

        return new Workspace($root, tool: $merge ? ToolLaunch::merge($absolute[0], $absolute[1], $absolute[2], $absolute[3]) : ToolLaunch::diff($absolute[0], $absolute[1]));
    }

    /** --git-setup: write (or, dry, print) the global config that makes porta git's tools. */
    // @mago-expect lint:no-boolean-flag-parameter -- --dry-run is the flag being honoured.
    private function setUpGit(OutputInterface $output, SymfonyStyle $io, bool $dryRun): int
    {
        $setup = new GitToolSetup($this->self, $this->git);
        if ($dryRun) {
            $output->writeln($setup->commands());

            return Command::SUCCESS;
        }
        $failed = $setup->apply();
        match ($failed) {
            null => $output->writeln([
                'porta is now git\'s difftool and mergetool:',
                '  git difftool          compare the working tree with the index, a file at a time',
                '  git mergetool         resolve each conflicted file; save, then Ctrl+Q',
            ]),
            default => $io->error(\sprintf('porta: could not write %s to the global git config — is git installed?', $failed)),
        };

        return null === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /** --self-update: fetch the latest release and swap it over the running phar (or, dry, say what is there). */
    private function updateSelf(OutputInterface $output, SymfonyStyle $io, bool $dryRun): int
    {
        if ('' === $this->phar) {
            $io->error(\sprintf(
                'porta: --self-update replaces the porta.phar it runs from, and this porta runs from %s — update it with `composer global update janalis/porta` or `git pull` instead.',
                $this->self,
            ));

            return Command::FAILURE;
        }

        return $this->updatePhar($output, $io, $dryRun);
    }

    /** --self-update from a phar: fetch the latest release and, unless dry or already current, install it. */
    // @mago-expect lint:no-boolean-flag-parameter -- --dry-run is the flag being honoured.
    private function updatePhar(OutputInterface $output, SymfonyStyle $io, bool $dryRun): int
    {
        $update = new SelfUpdate($this->process, $this->env);
        try {
            $latest = $update->latest();
            $newer = SelfUpdate::isNewer($latest, $this->version);
            if ($newer && !$dryRun) {
                $update->install($latest, $this->phar, \PHP_BINARY);
            }
        } catch (\RuntimeException $e) {
            $io->error('porta: self-update: '.$e->getMessage());

            return Command::FAILURE;
        }
        $output->writeln(match (true) {
            !$newer => \sprintf('porta %s is up to date (latest release: %s).', $this->version, $latest),
            $dryRun => \sprintf('porta %s can be updated to %s.', $this->version, $latest),
            default => \sprintf('porta updated: %s → %s.', $this->version, $latest),
        });

        return Command::SUCCESS;
    }

    /**
     * Run a command for --self-update and --git-setup: its exit code and
     * stdout. Excluded from coverage: the tests hand fakes in — the real ones
     * reach GitHub and write the user's global git config.
     *
     * @param list<string> $command
     *
     * @return array{int, string}
     *
     * @codeCoverageIgnore
     */
    private static function runProcess(array $command, ?string $stdin = null): array
    {
        try {
            $process = new Process($command, input: $stdin, timeout: 300);
            $process->run();

            return [(int) $process->getExitCode(), $process->getOutput()];
        } catch (\Throwable) {
            return [127, '']; // the binary is not there
        }
    }

    /**
     * `git <args>`, through {@see runProcess()}.
     *
     * @param list<string> $args
     *
     * @return array{int, string}
     *
     * @codeCoverageIgnore
     */
    private static function runGit(array $args): array
    {
        return self::runProcess(['git', ...$args]);
    }

    /**
     * Boot the real IDE. Excluded from coverage: it takes over the terminal and
     * blocks on the event loop, so it can only run interactively.
     *
     * @codeCoverageIgnore
     */
    private static function launchIde(Workspace $workspace, ?string $lspCommand, LspMode $lsp, Ansi $ansi, \Closure $onBooted): int
    {
        $root = $workspace->rootDir;
        $file = $workspace->initialFile;
        $line = $workspace->line;
        // Each project is its own Ide with its own container, run one after the
        // other in the one process. Switching project is therefore an ordinary
        // QUIT — buffers saved, terminal restored, plugins shut down — followed
        // by an ordinary boot, which is why nothing here has to know what a
        // project holds. The alternative, rebuilding a live container under a
        // running event loop, would have to.
        while (true) {
            $ide = new Ide(
                rootDir: $root,
                initialFile: $file,
                initialLine: $line,
                lspCommand: $lspCommand,
                lspDisabled: LspMode::Disabled === $lsp,
                ansi: Ansi::Enabled === $ansi,
                // REQUEST_TIME_FLOAT is the interpreter's own start: the trace's
                // prelude covers PHP + autoload, which no later clock can see.
                bootTrace: new BootTrace($_SERVER['REQUEST_TIME_FLOAT'] ?? \microtime(true)),
                tool: $workspace->tool,
            );
            $onBooted();
            $ide->run();

            $next = $ide->reopenAt();
            if (null === $next) {
                return $ide->exitCode();
            }
            // The next project opens on whatever its own session restored, the
            // way a fresh launch with no file argument does.
            $root = $next;
            $file = null;
            $line = null;
        }
    }
}
