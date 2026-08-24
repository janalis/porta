<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Container\Ansi;
use Porta\Container\LspMode;
use Porta\Ide;
use Porta\Support\DiagnosticLog;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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

        Keys: Ctrl+T/F2 switch tree/editor · Ctrl+S save · Ctrl+Space complete ·
        F12/Ctrl+G go to definition (Alt+Left back) · Ctrl+Z/Ctrl+R undo/redo · Ctrl+Q quit.

        Language server resolution: --lsp, then .porta.json {"lsp": …}, then $PORTA_LSP,
        then the enabled language-server plugins (phpantom for PHP; enable the phpactor
        plugin to use phpactor instead; symfony-lsp for Symfony projects — twig, yaml,
        xml, json and dotenv, plus PHP when neither PHP server is enabled), then
        typescript-language-server / pyright on PATH.
        HELP,
    usages: ['src/', 'src/Ide.php:200', '--no-lsp .'],
)]
final class PortaCommand extends Command
{
    use CommandErrorStream;

    /** @var callable(Workspace, ?string, LspMode, Ansi, \Closure): void */
    private $launch;

    /** @var callable(): bool */
    private $isInteractive;

    /**
     * @param (callable(Workspace, ?string, LspMode, Ansi, \Closure): void)|null $launch        defaults to booting a real {@see Ide}; the trailing closure is
     *                                                                                          called between boot and run, so a crash can say which half died
     * @param (callable(): bool)|null                                            $isInteractive defaults to a POSIX tty check
     */
    public function __construct(?callable $launch = null, ?callable $isInteractive = null)
    {
        parent::__construct();
        $this->launch = $launch ?? self::launchIde(...);
        // stream_isatty is core PHP — the posix_isatty it replaced needed
        // ext-posix, and a box without it was refused as "not interactive".
        $this->isInteractive = $isInteractive ?? static fn (): bool => stream_isatty(\STDIN) && stream_isatty(\STDOUT);
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('File or directory to open (accepts the file:line shorthand)')]
        ?string $path = null,
        #[Option('Language server command (overrides .porta.json and $PORTA_LSP)')]
        ?string $lsp = null,
        #[Option('Start with no language server')]
        // @mago-expect lint:no-boolean-flag-parameter -- Symfony Console's #[Option] shape: a bool option IS a flag.
        bool $noLsp = false,
        #[Option('Open the file scrolled to this line', shortcut: 'l')]
        ?string $line = null,
    ): int {
        // Failures speak on stderr so a piped stdout stays clean (the
        // CoverageCheckCommand pattern); CommandTester's plain output has no
        // error channel and falls back to the one stream.
        $errors = self::errorOutput($output);
        $io = new SymfonyStyle($input, $errors);

        if (null !== $lsp && $noLsp) {
            $io->error('porta: --lsp and --no-lsp contradict each other; pass one.');

            return Command::INVALID;
        }

        if (null !== $line && !ctype_digit($line)) {
            $io->error(\sprintf('porta: --line expects a line number, got "%s".', $line));

            return Command::INVALID;
        }

        $target = $path ?? (string) getcwd();
        try {
            $workspace = new WorkspaceResolver()->resolve($target, (string) getcwd());
        } catch (\InvalidArgumentException $e) {
            $io->error('porta: '.$e->getMessage());

            return Command::FAILURE;
        }

        // An explicit --line overrides the file:line shorthand.
        $openAt = null !== $line ? max(1, (int) $line) : $workspace->line;
        $workspace = new Workspace($workspace->rootDir, $workspace->initialFile, $openAt);

        if (!($this->isInteractive)()) {
            $io->error('porta: an interactive terminal is required.');

            return Command::FAILURE;
        }
        // --ansi/--no-ansi are Symfony's global options; they resolve into the
        // output decoration, which we thread to the IDE as its color mode.
        $booted = false;
        try {
            ($this->launch)($workspace, $lsp, $noLsp ? LspMode::Disabled : LspMode::Enabled, $output->isDecorated() ? Ansi::Enabled : Ansi::Disabled, static function () use (&$booted): void {
                $booted = true;
            });
        } catch (\Throwable $e) {
            if ($output->isVerbose()) {
                throw $e; // -v: let the console runner render the full trace
            }
            $io->error(\sprintf('porta: fatal: %s', $e->getMessage()));
            if (!$booted) {
                // The likeliest BOOT failure is a container that no longer
                // compiles against a stale dump; say the remedy instead of
                // dumping a stack trace at someone who just typed `porta .`.
                $errors->writeln('A stale container cache can break startup — delete ~/.cache/porta (or $PORTA_CACHE_DIR) and retry. Run with -v for the full trace.');

                return Command::FAILURE;
            }
            // A crash mid-session has nothing to do with the cache. Keep the
            // evidence where the in-session error handler keeps its own — the
            // crash took the trace with it, and a -v rerun reproduces nothing.
            DiagnosticLog::forRoot($workspace->rootDir, 'error')->append(\sprintf("%s: %s\n%s", $e::class, $e->getMessage(), $e->getTraceAsString()));
            $errors->writeln('porta crashed — the trace was appended to .porta/error.log. Run with -v to see it here.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Boot the real IDE. Excluded from coverage: it takes over the terminal and
     * blocks on the event loop, so it can only run interactively.
     *
     * @codeCoverageIgnore
     */
    private static function launchIde(Workspace $workspace, ?string $lspCommand, LspMode $lsp, Ansi $ansi, \Closure $onBooted): void
    {
        $ide = new Ide(
            rootDir: $workspace->rootDir,
            initialFile: $workspace->initialFile,
            initialLine: $workspace->line,
            lspCommand: $lspCommand,
            lspDisabled: LspMode::Disabled === $lsp,
            ansi: Ansi::Enabled === $ansi,
        );
        $onBooted();
        $ide->run();
    }
}
