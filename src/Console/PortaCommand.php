<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Config\PortaHome;
use Porta\Config\WorkspaceTrust;
use Porta\Container\Ansi;
use Porta\Container\LspMode;
use Porta\Container\ToolLaunch;
use Porta\Filesystem\ProjectLocation;
use Porta\Ide;
use Porta\Install\GitToolSetup;
use Porta\Install\Installation;
use Porta\Install\InstallMethod;
use Porta\Install\PortaBinary;
use Porta\Support\BootTrace;
use Porta\Support\DiagnosticLog;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
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
          <info>porta --self-update</info>       update porta to the latest release — the phar itself, or
                                   through brew or composer global; a checkout is told how
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
     * @param Installation|null                                                 $installation  how this porta was installed, which --self-update follows; defaults
     *                                                                                         to reading it off $phar and the package's folder
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
        private readonly ?Installation $installation = null,
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
        #[Option('Update porta to the latest release, the way it was installed')]
        bool $selfUpdate = false,
        #[Option('With --git-setup: print the git config commands; with --self-update: only say what is available')]
        bool $dryRun = false,
        #[Option('Turn a bundled plugin on for this project, besides what its config enables (repeatable)')]
        array $plugin = [],
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
            default => $this->open($output, $errors, $io, $path, $line, $diff, $merge, $lsp, $noLsp, \array_values(\array_map(\strval(...), $plugin))),
        };
    }

    /**
     * An ordinary or a git tool launch: the workspace the arguments name,
     * opened in the IDE.
     *
     * @param list<string> $path
     * @param list<string> $plugins
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
        array $plugins,
    ): int {
        $cwd = (string) \getcwd();
        try {
            $workspace = $diff || $merge ? self::toolWorkspace($cwd, $path, $merge) : self::editWorkspace($cwd, $path[0] ?? $cwd, $line, $plugins);
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
        // Nor does a remote project's cache: nothing there runs either way.
        if (null === $workspace->tool && null === ProjectLocation::forRoot($workspace->rootDir)->remote && $this->trust->shouldAsk($workspace->rootDir)) {
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
    /** @param list<string> $plugins */
    private static function editWorkspace(string $cwd, string $target, ?string $line, array $plugins): Workspace
    {
        $workspace = new WorkspaceResolver()->resolve($target, $cwd);
        $openAt = null !== $line ? \max(1, (int) $line) : $workspace->line;

        return new Workspace($workspace->rootDir, $workspace->initialFile, $openAt, plugins: $plugins);
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
    /**
     * --self-update: compare with the latest release and, unless dry or
     * current, update the way this porta was installed — the phar swaps
     * itself, brew and a global Composer are run, and a checkout or a
     * project's dependency is only told the command: those trees are not
     * Porta's to change.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- --dry-run is the flag being honoured.
    private function updateSelf(OutputInterface $output, SymfonyStyle $io, bool $dryRun): int
    {
        $installation = $this->installation ?? Installation::detect($this->phar, PortaBinary::packageDir(), $this->env);
        $runs = $installation->method->updatesItself();
        $update = new SelfUpdate($this->process, $this->env);
        try {
            $latest = $update->latest();
            $newer = SelfUpdate::isNewer($latest, $this->version);
            if ($newer && !$dryRun && $runs) {
                $this->install($update, $installation, $latest);
            }
        } catch (\RuntimeException $e) {
            $io->error('porta: self-update: '.$e->getMessage());

            return Command::FAILURE;
        }
        $output->writeln(match (true) {
            !$newer => \sprintf('porta %s is up to date (latest release: %s).', $this->version, $latest),
            $dryRun || !$runs => \sprintf('porta %s can be updated to %s.', $this->version, $latest),
            default => \sprintf('porta updated: %s → %s.', $this->version, $latest),
        });

        return $runs || !$newer ? Command::SUCCESS : $this->tellHow($output, $installation, $dryRun);
    }

    /** An install Porta does not update itself: the command that would, and — unless only asked — a failure. */
    // @mago-expect lint:no-boolean-flag-parameter -- --dry-run is the flag being honoured.
    private function tellHow(OutputInterface $output, Installation $installation, bool $dryRun): int
    {
        $output->writeln(\sprintf(
            'This porta is %s %s: update it with `%s`.',
            InstallMethod::Checkout === $installation->method ? 'the git checkout at' : 'a dependency of the project at',
            $installation->path,
            $installation->commandLine(),
        ));

        // Asked to update and nothing was: a script must not read success.
        return $dryRun ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Install $latest the way $installation says: the phar swaps itself;
     * otherwise its package manager runs, and must visibly have changed it.
     *
     * @throws \RuntimeException saying what went wrong
     */
    private function install(SelfUpdate $update, Installation $installation, string $latest): void
    {
        if (InstallMethod::Phar === $installation->method) {
            $update->install($latest, $installation->path, \PHP_BINARY);

            return;
        }
        $before = $installation->fingerprint();
        foreach ($installation->commands() as $command) {
            // @mago-expect lint:literal-named-argument -- a closure's parameters have no names to pass it by
            [$code] = ($this->process)($command, null);
            if (0 !== $code) {
                throw new \RuntimeException(\sprintf('`%s` failed (exit %d); run it yourself to see why.', \implode(' ', $command), $code));
            }
        }
        // brew's tap and Packagist follow a release by minutes: an upgrade
        // that finds nothing yet exits 0 and changes nothing.
        if ($installation->fingerprint() === $before) {
            throw new \RuntimeException(\sprintf(
                '`%s` left porta as it was, though %s is out — it may not have reached %s yet; try again later.',
                $installation->commandLine(),
                $latest,
                InstallMethod::Homebrew === $installation->method ? 'the Homebrew tap' : 'Packagist',
            ));
        }
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
            // brew upgrades refresh their taps first, and may upgrade PHP along.
            $process = new Process($command, input: $stdin, timeout: 900);
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
        $plugins = $workspace->plugins;
        $resume = \getenv('PORTA_RESTART_DIRECTORY');
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
                plugins: $plugins,
                restartDirectory: \is_string($resume) && '' !== $resume ? $resume : null,
            );
            $onBooted();
            $ide->run();

            $directory = $ide->restartDirectory();
            if (null !== $directory) {
                return self::restart($ide, $directory, $root, $plugins, $lspCommand, $lsp, $ansi);
            }
            $resume = false;
            $next = $ide->reopenAt();
            if (null === $next) {
                return $ide->exitCode();
            }
            // The next project opens on whatever its own session restored, the
            // way a fresh launch with no file argument does — and on its own
            // config: --plugin named the project it was given with.
            $plugins = $next === $root ? $plugins : [];
            $root = $next;
            $file = null;
            $line = null;
        }
    }

    /**
     * @param list<string> $plugins
     * @codeCoverageIgnore Interactive TTY handoff; RestartLauncher and the helper are exercised in child processes.
     */
    private static function restart(Ide $ide, string $directory, string $root, array $plugins, ?string $lspCommand, LspMode $lsp, Ansi $ansi): int
    {
        $binary = PortaBinary::entry($_SERVER['SCRIPT_FILENAME'], PortaBinary::path());
        $php = PortaBinary::interpreter(\PHP_BINARY);
        $launch = [$php, $binary, $root];
        if (Ansi::Disabled === $ansi) {
            $launch[] = '--no-ansi';
        }
        if (LspMode::Disabled === $lsp) {
            $launch[] = '--no-lsp';
        }
        if (LspMode::Disabled !== $lsp && null !== $lspCommand) {
            $launch[] = '--lsp='.$lspCommand;
        }
        foreach ($plugins as $plugin) {
            $launch[] = '--plugin='.$plugin;
        }
        $home = PortaHome::base();
        $state = null === $home ? \dirname($binary) : $home.'/.porta/updates';
        new Filesystem()->mkdir($state, mode: 0o700);
        $installation = Installation::current();
        $key = InstallMethod::Homebrew === $installation->method ? 'homebrew' : $installation->path;
        $helper = new RestartLauncher()->prepare(
            $directory,
            $root,
            $launch,
            $ide->updatesCore() ? [$php, $binary, '--self-update', '--no-interaction', '--no-ansi'] : [],
            $state.'/'.\hash('sha256', $key).'.lock',
        );
        if (null === $helper) {
            return 1;
        }
        $pipes = [];
        /** @var non-empty-string $root */
        $process = \proc_open([\PHP_BINARY, $helper, $directory.'/launch.json'], [\STDIN, \STDOUT, \STDERR], $pipes, $root);

        return \is_resource($process) ? \proc_close($process) : 1;
    }
}
