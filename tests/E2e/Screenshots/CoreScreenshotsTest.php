<?php

declare(strict_types=1);

namespace Porta\Tests\E2e\Screenshots;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\PreservesShell;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Screenshots\ShopProject;
use Porta\Tests\Support\Screenshots\TakesScreenshots;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\Widgets;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The screenshots in Porta's own docs, one scenario each: the real IDE, with
 * the plugins it ships, over {@see ShopProject}, driven to the screen a page
 * describes.
 */
final class CoreScreenshotsTest extends TestCase
{
    use PreservesPath;
    use PreservesPortaHome;
    use PreservesShell;
    use RunsIde;
    use TakesScreenshots;
    use UsesBundledPlugins;

    private const int COLUMNS = 140;
    private const int ROWS = 36;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        \putenv('ZDOTDIR');
    }

    public function testOverview(): void
    {
        $project = ShopProject::create();
        $this->runIde(
            $project,
            $project->path('src/Checkout/CheckoutController.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    $this->screenshot('overview', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testCompletion(): void
    {
        $project = ShopProject::create();
        $this->runIde(
            $project,
            $project->path('src/Checkout/CheckoutController.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    // Onto line 22, after the comment, and start a new call on $order.
                    foreach (\range(1, end: 21) as $ignored) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\x1b[F"); // end
                    self::type($tui, "\n\$order->");
                    $this->await(static fn (): bool => Widgets::editor($tui)->isCompletionOpen(), what: 'the completion popup to open');
                    $this->awaitPainted($tui, $terminal, 'isEmpty');
                    $this->screenshot('completion', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testFindAction(): void
    {
        $project = ShopProject::create();
        $this->runIde(
            $project,
            $project->path('src/Order/Order.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a
                    self::type($tui, 'format');
                    $this->awaitPainted($tui, $terminal, 'Format on Save');
                    $this->screenshot('find-action', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testFindInProject(): void
    {
        $project = ShopProject::create();
        $this->runIde(
            $project,
            $project->path('src/Order/Order.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    self::type($tui, "total()\n");
                    $this->awaitPainted($tui, $terminal, 'CheckoutController.php');
                    $this->screenshot('find-in-project', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testSettings(): void
    {
        $project = ShopProject::create();
        $this->runIde(
            $project,
            $project->path('src/Order/Order.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->awaitPainted($tui, $terminal, 'Auto-pairs');
                    $this->screenshot('settings', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testTerminal(): void
    {
        \putenv('PATH=/usr/bin:/bin');
        // zsh where there is one (the docs build installs it): sh, with no
        // controlling tty here, opens on a job-control warning.
        \putenv('SHELL='.(\is_file('/bin/zsh') ? '/bin/zsh' : '/bin/sh'));
        $project = ShopProject::create();
        // An empty dotdir: the reader's prompt, not the machine's that built the docs.
        // Read first, it turns off the machine's own files: Ubuntu's
        // /etc/zsh/zshrc runs compinit, which stops on a question.
        $project->write('../zdotdir/.zshenv', "unsetopt GLOBAL_RCS\n");
        $project->write('../zdotdir/.zshrc', "PROMPT='%1~ \$ '\n");
        \putenv('ZDOTDIR='.\dirname($project->dir).'/zdotdir');
        $this->runIde(
            $project,
            $project->path('src/Order/Order.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    Services::resolve($ide, CommandRegistry::class)->run('ide_terminal');
                    // Type at the prompt, not before the shell is up to echo it.
                    $this->awaitPainted($tui, $terminal, '$ ');
                    self::type($tui, "ls src/Order\n");
                    $this->awaitPainted($tui, $terminal, 'OrderLine.php');
                    $this->screenshot('terminal', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    public function testDebugger(): void
    {
        $adapter = \PHP_BINARY.' '.\dirname(__DIR__, levels: 2).'/Support/mock-dap-adapter.php';
        $project = ShopProject::create(config: ['languages' => ['php' => ['debug' => ['adapter' => $adapter]]]]);
        $this->runIde(
            $project,
            $project->path('src/Order/Order.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitLspReady($tui, $terminal, ShopProject::SERVER);
                    Widgets::editor($tui)->getDocument()->setCursor(32, 0); // the line that adds each subtotal
                    $tui->handleInput("\x1b[19;5~"); // ctrl+f8: a breakpoint
                    $tui->handleInput("\x1b[20;2~"); // shift+f9: debug
                    // Locals are the last of the stop's round trips to land.
                    $this->awaitPainted($tui, $terminal, '$x = 42');
                    $this->screenshot('debugger', $tui, $terminal, $ide);
                },
            ],
            columns: self::COLUMNS,
            rows: self::ROWS,
        );
    }

    /** Type $text a key at a time, the way a user does. */
    private static function type(Tui $tui, string $text): void
    {
        foreach (\mb_str_split($text) as $key) {
            $tui->handleInput($key);
        }
    }
}
