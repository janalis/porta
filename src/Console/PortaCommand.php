<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Container\Ansi;
use Porta\Container\LspMode;
use Porta\Ide;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `porta` command: resolves the target path/options and launches the IDE.
 *
 * The IDE launch and the interactive-terminal check are injected so the command
 * logic is fully testable without a TTY or a real editor session.
 */
#[AsCommand(name: 'porta', description: 'Open the Porta terminal IDE for PHP projects')]
final class PortaCommand extends Command
{
    /** @var callable(Workspace, ?string, LspMode, Ansi): void */
    private $launch;

    /** @var callable(): bool */
    private $isInteractive;

    /**
     * @param (callable(Workspace, ?string, LspMode, Ansi): void)|null $launch        defaults to booting a real {@see Ide}
     * @param (callable(): bool)|null                                  $isInteractive defaults to a POSIX tty check
     */
    public function __construct(?callable $launch = null, ?callable $isInteractive = null)
    {
        parent::__construct();
        $this->launch = $launch ?? self::launchIde(...);
        $this->isInteractive = $isInteractive ?? static fn (): bool => \function_exists('posix_isatty') && posix_isatty(\STDIN) && posix_isatty(\STDOUT);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'File or directory to open (accepts the file:line shorthand)')
            ->addOption('lsp', null, InputOption::VALUE_REQUIRED, 'Language server command (overrides .porta.json and $PORTA_LSP)')
            ->addOption('no-lsp', null, InputOption::VALUE_NONE, 'Start with no language server')
            ->addOption('line', 'l', InputOption::VALUE_REQUIRED, 'Open the file scrolled to this line')
            ->setHelp(<<<'HELP'
                Open a PHP project (or a single file) in the Porta terminal IDE.

                  <info>porta</info>                     open the current directory
                  <info>porta src/</info>                open a directory
                  <info>porta src/Ide.php:200</info>     open a file at line 200
                  <info>porta --no-lsp .</info>          start without a language server

                Keys: Ctrl+T/F2 switch tree/editor · Ctrl+S save · Ctrl+Space complete ·
                F12/Ctrl+G go to definition (Alt+Left back) · Ctrl+Z/Ctrl+R undo/redo · Ctrl+Q quit.

                Language server resolution: --lsp, then .porta.json {"lsp": …}, then $PORTA_LSP,
                then vendor/bin/phpactor, then phpactor / intelephense on PATH.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = (string) ($input->getArgument('path') ?? getcwd());
        try {
            $workspace = new WorkspaceResolver()->resolve($target, (string) getcwd());
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>porta: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        // An explicit --line overrides the file:line shorthand.
        /** @var string|null $lineOption */
        $lineOption = $input->getOption('line');
        $line = null !== $lineOption ? max(1, (int) $lineOption) : $workspace->line;
        $workspace = new Workspace($workspace->rootDir, $workspace->initialFile, $line);

        if (!($this->isInteractive)()) {
            $output->writeln('<error>porta: an interactive terminal is required.</error>');

            return Command::FAILURE;
        }

        /** @var string|null $lsp */
        $lsp = $input->getOption('lsp');
        /** @var bool $noLsp */
        $noLsp = $input->getOption('no-lsp');
        // --ansi/--no-ansi are Symfony's global options; they resolve into the
        // output decoration, which we thread to the IDE as its color mode.
        ($this->launch)($workspace, $lsp, $noLsp ? LspMode::Disabled : LspMode::Enabled, $output->isDecorated() ? Ansi::Enabled : Ansi::Disabled);

        return Command::SUCCESS;
    }

    /**
     * Boot the real IDE. Excluded from coverage: it takes over the terminal and
     * blocks on the event loop, so it can only run interactively.
     *
     * @codeCoverageIgnore
     */
    private static function launchIde(Workspace $workspace, ?string $lspCommand, LspMode $lsp, Ansi $ansi): void
    {
        new Ide(
            rootDir: $workspace->rootDir,
            initialFile: $workspace->initialFile,
            initialLine: $workspace->line,
            lspCommand: $lspCommand,
            lspDisabled: LspMode::Disabled === $lsp,
            ansi: Ansi::Enabled === $ansi,
        )->run();
    }
}
