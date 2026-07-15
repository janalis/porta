<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * Boots the full IDE against a VirtualTerminal inside a scratch project
 * wired to the mock LSP server, simulates keystrokes on a timeline and
 * asserts on the rendered screen.
 *
 * Assertions inside step closures throw through Ide::run() and fail the
 * test naturally; the TUI's finally-block still restores state.
 */
final class IdeTest extends TestCase
{
    private TempProject $project;
    private VirtualTerminal $terminal;
    private Ide $ide;

    protected function setUp(): void
    {
        $this->project = new TempProject(
            [
                'composer.json' => json_encode(
                    [
                        'name' => 'acme/demo',
                        'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
                    ],
                    \JSON_UNESCAPED_SLASHES,
                ),
                'src/Target.php' => "<?php\n\n// line 2\nclass Target\n{\n}\n",
                'src/Demo.php' => <<<'PHP'
                    <?php

                    class Demo
                    {
                        public function greet(string $name): string
                        {
                            return "Hello {$name}";
                        }
                    }

                    PHP,
                '.porta.json' => json_encode([
                    'lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/mock-lsp-server.php',
                    'keymap' => ['duplicate_line' => ['f8']], // prove .porta.json rebinding works
                ]),
            ],
            'porta-e2e-',
        );

        $this->terminal = new VirtualTerminal(columns: 100, rows: 30);
        $this->ide = new Ide($this->project->dir, $this->project->path('src/Demo.php'), $this->terminal);
    }

    private function screen(): string
    {
        // Flush any pending render before reading, so assertions do not
        // depend on the adaptive ticker having fired between steps.
        $this->ide->getTui()->tick();

        return AnsiUtils::stripAnsiCodes($this->terminal->getOutput());
    }

    private function step(float $at, callable $fn): void
    {
        EventLoop::delay($at, $fn);
    }

    public function testFullScenario(): void
    {
        $tui = $this->ide->getTui();
        $screen = $this->screen(...);
        $editor = static fn () => $tui->getById('editor');

        // --- Startup ---------------------------------------------------------
        $this->step(0.4, function () use ($screen, $tui): void {
            $s = $screen();
            $this->assertStringContainsString('src/', $s, 'file tree shows project dirs');
            $this->assertStringContainsString('composer.json', $s);
            $this->assertStringContainsString('class Demo', $s, 'editor shows the opened file');
            $this->assertStringContainsString('src/Demo.php', $s, 'status bar shows the path');
            $this->assertStringContainsString('LSP:', $s);
            $this->assertStringContainsString("\x1b[", $tui->getTerminal()->getOutput(), 'ANSI output emitted');
        });

        // --- Completion (mock server) ---------------------------------------
        $this->step(0.5, static function () use ($tui): void {
            $tui->handleInput("\x1b[B");
            $tui->handleInput("\x1b[B");
            $tui->handleInput("\x00"); // ctrl+space
        });
        $this->step(1.0, function () use ($screen, $editor): void {
            $this->assertTrue($editor()->isCompletionOpen());
            $this->assertStringContainsString('strlen', $screen());
            $this->assertStringContainsString('str_replace', $screen());
        });
        $this->step(1.1, static fn () => $tui->handleInput("\r") /* accept */);
        $this->step(1.4, function () use ($screen, $editor): void {
            $doc = $editor()->getDocument();
            $this->assertStringContainsString('strlen(', $doc->getText());
            // Deferred auto-import fetched via completionItem/resolve landed above.
            $this->assertStringContainsString('use function strlen;', $doc->getText());
            $this->assertFalse($editor()->isCompletionOpen());
            $this->assertStringContainsString('✗ 1', $screen(), 'diagnostic from mock server in status bar');
        });

        // --- Save -------------------------------------------------------------
        $this->step(1.5, static fn () => $tui->handleInput("\x13"));
        $this->step(1.8, function () use ($screen): void {
            $this->assertStringContainsString('Saved src/Demo.php', $screen());
            $this->assertStringContainsString('strlen(', (string) file_get_contents($this->project->path('src/Demo.php')));
        });

        // --- Definition jump + back-nav ---------------------------------------
        $this->step(1.85, static fn () => $tui->handleInput("\x1b[24~")); // F12
        $this->step(2.0, function () use ($tui, $editor): void {
            $this->assertStringEndsWith('Target.php', (string) $editor()->getFilePath());
            $this->assertSame(3, $editor()->getDocument()->getCursorLine());
            $tui->handleInput("\x1b[1;7D"); // ctrl+alt+left -> back
        });
        $this->step(2.1, function () use ($editor, $tui): void {
            $this->assertStringEndsWith('Demo.php', (string) $editor()->getFilePath());
            $tui->handleInput("\x14"); // ctrl+t -> tree
        });

        // --- Tree navigation ----------------------------------------------------
        $this->step(2.2, function () use ($tui): void {
            $this->assertSame('tree', $tui->getFocus()?->getId());
            $tui->handleInput("\x1b[B"); // .porta.json
            $tui->handleInput("\x1b[B"); // composer.json
            $tui->handleInput("\r");
        });
        $this->step(2.4, function () use ($tui, $editor, $screen): void {
            $this->assertStringEndsWith('composer.json', (string) $editor()->getFilePath());
            $this->assertSame('editor', $tui->getFocus()?->getId());
            $this->assertStringContainsString('acme/demo', $screen());
        });

        // --- Buffers: tab bar, cycling, recent files ----------------------------
        $this->step(2.45, function () use ($screen, $tui): void {
            $s = $screen();
            $this->assertStringContainsString('Demo.php', $s);
            $this->assertStringContainsString('Target.php', $s);
            $tui->handleInput("\x1b[1;5C"); // ctrl+right wraps to Demo.php
        });
        $this->step(2.55, function () use ($editor, $tui): void {
            $this->assertStringEndsWith('Demo.php', (string) $editor()->getFilePath());
            $tui->handleInput("\x05"); // ctrl+e
        });
        $this->step(2.65, function () use ($editor, $screen, $tui): void {
            $this->assertTrue($editor()->isOverlayOpen());
            $this->assertStringContainsString('Recent Files', $screen());
            $tui->handleInput("\r"); // pick previous (composer.json)
        });
        $this->step(2.75, function () use ($editor): void {
            $this->assertStringEndsWith('composer.json', (string) $editor()->getFilePath());
        });

        // --- Find bar --------------------------------------------------------------
        $this->step(2.8, function () use ($tui): void {
            $tui->handleInput("\x06"); // ctrl+f
            $this->assertSame('find-bar', $tui->getFocus()?->getId());
            foreach (str_split('acme') as $ch) {
                $tui->handleInput($ch);
            }
        });
        $this->step(2.9, function () use ($screen, $editor, $tui): void {
            $this->assertStringContainsString('Find: acme', $screen());
            $this->assertStringContainsString('[1/2]', $screen(), 'smart-case: lowercase query matches acme/demo and Acme\\');
            $doc = $editor()->getDocument();
            $this->assertStringContainsString('acme', $doc->getLine($doc->getCursorLine()));
            $tui->handleInput("\x1b"); // esc
        });
        $this->step(3.0, function () use ($tui): void {
            $this->assertSame('editor', $tui->getFocus()?->getId(), 'editor refocused after find close');
        });

        // --- Go to file palette -------------------------------------------------------
        $this->step(3.05, function () use ($tui): void {
            $tui->handleInput("\x10"); // ctrl+p
            $this->assertSame('goto-file', $tui->getFocus()?->getId());
            foreach (str_split('target') as $ch) {
                $tui->handleInput($ch);
            }
        });
        $this->step(3.15, function () use ($screen, $tui): void {
            $this->assertStringContainsString('Target.php', $screen());
            $tui->handleInput("\r");
        });
        $this->step(3.25, function () use ($editor, $tui): void {
            $this->assertStringEndsWith('Target.php', (string) $editor()->getFilePath());
            $tui->handleInput("\x05"); // back to composer.json
            $tui->handleInput("\r");
        });

        // --- Project search --------------------------------------------------------------
        $this->step(3.35, function () use ($editor, $tui): void {
            $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → the Find-in-Files modal
            $this->assertTrue($editor()->isFindModalOpen(), 'the find modal opened');
            foreach (str_split('class Target') as $ch) {
                $tui->handleInput($ch);
            }
        });
        $this->step(3.75, function () use ($screen, $tui): void {
            // The modal's live preview groups matches under a file header.
            $this->assertStringContainsString('src/Target.php', $screen(), 'grouped under its file header');
            $this->assertStringContainsString('1 results', $screen());
            $tui->handleInput("\r"); // the first match is pre-selected → jump
        });
        $this->step(3.85, function () use ($editor, $tui): void {
            $this->assertStringEndsWith('Target.php', (string) $editor()->getFilePath());
            $this->assertSame(3, $editor()->getDocument()->getCursorLine());
            $this->assertSame('editor', $tui->getFocus()?->getId());
        });

        // --- Hover quick-doc ----------------------------------------------------------------
        $this->step(3.95, static fn () => $tui->handleInput("\x1b[1;2P")); // F1
        $this->step(4.05, function () use ($editor, $screen, $tui): void {
            $this->assertTrue($editor()->isOverlayOpen());
            $this->assertStringContainsString('greet(): string', $screen());
            $tui->handleInput("\x1b");
        });

        // --- Go to line -------------------------------------------------------------------------
        $this->step(4.15, static fn () => $tui->handleInput("\x07")); // ctrl+g
        $this->step(4.22, function () use ($screen, $tui): void {
            $this->assertStringContainsString('Go to line:', $screen());
            $tui->handleInput('1');
            $tui->handleInput("\r");
        });
        $this->step(4.3, function () use ($editor): void {
            $this->assertSame(0, $editor()->getDocument()->getCursorLine());
        });

        // --- Rename via WorkspaceEdit ----------------------------------------------------------------
        $this->step(4.35, static function () use ($tui): void {
            $tui->handleInput("\x1b[17;2~"); // shift+f6 -> prepareRename validates, then the prompt opens
        });
        $this->step(4.42, static function () use ($tui): void {
            foreach (str_split('Renamed') as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        });
        $this->step(4.5, function () use ($editor, $screen, $tui): void {
            $this->assertSame('class Renamed', $editor()->getDocument()->getLine(3));
            $this->assertStringContainsString('Renamed in 1 file', $screen());
            $tui->handleInput("\x1b[18;3~"); // alt+f7 usages
        });

        // --- Find usages ------------------------------------------------------------------------------
        $this->step(4.65, function () use ($screen, $tui): void {
            $this->assertSame('results', $tui->getFocus()?->getId());
            $this->assertStringContainsString('Usages', $screen());
            $this->assertStringContainsString('2 results', $screen());
            $tui->handleInput("\x1b");
        });

        // --- Signature help --------------------------------------------------------------------------------
        $this->step(4.75, static fn () => $tui->handleInput('('));
        $this->step(4.85, function () use ($screen, $tui): void {
            $this->assertStringContainsString('greet(string $name): string', $screen());
            $tui->handleInput("\x05"); // back to composer.json
            $tui->handleInput("\r");
        });

        // --- Keymap override from .porta.json ------------------------------------------------------------------
        $this->step(4.95, function () use ($editor, $tui): void {
            $editor()->getDocument()->setCursor(0, 0);
            $tui->handleInput("\x1b[19~"); // F8, rebound to duplicate_line
            $doc = $editor()->getDocument();
            $this->assertSame($doc->getLine(0), $doc->getLine(1), 'config-rebound key duplicates line');
        });

        // --- Quit: dirty warning, then confirm ----------------------------------------------------------------------
        $this->step(5.05, static fn () => $tui->handleInput("\x11"));
        $this->step(5.15, function () use ($screen, $tui): void {
            $this->assertStringContainsString('Unsaved changes', $screen());
            $tui->handleInput("\x11");
        });

        $watchdog = EventLoop::delay(9.0, static function () use ($tui): void {
            $tui->stop();
            throw new \RuntimeException('watchdog: IDE did not quit before timeout');
        });

        $this->ide->run();
        EventLoop::cancel($watchdog);

        $this->assertFalse($tui->isRunning(), 'run() returned after ctrl+q');
    }

    /**
     * Search Everywhere: Ctrl+Shift+E opens the centered modal; the empty query
     * seeds recent files; a filename finds a file (sync) and Enter opens it; a
     * class name drives the async LSP workspace/symbol source and Enter jumps to
     * it; typing then Esc cancels the pending debounce and refocuses the editor.
     */
    public function testSearchEverywhere(): void
    {
        $tui = $this->ide->getTui();
        $screen = $this->screen(...);
        $editor = static fn () => $tui->getById('editor');

        // Ctrl+Shift+E → modal opens; the empty query seeds recent files.
        $this->step(0.5, function () use ($screen, $tui, $editor): void {
            $tui->handleInput("\x1b[101;6u"); // ctrl+shift+e
            $this->assertTrue($editor()->isSearchOverlayOpen());
            $s = $screen();
            $this->assertStringContainsString('Search Everywhere', $s, 'the modal title');
            $this->assertStringContainsString('Demo.php', $s, 'empty query shows recent files');
        });

        // Type a filename → the Files source lists it (synchronously); Enter opens it.
        $this->step(0.7, static function () use ($tui): void {
            foreach (str_split('Target') as $ch) {
                $tui->handleInput($ch);
            }
        });
        $this->step(0.9, function () use ($screen, $tui): void {
            $this->assertStringContainsString('Target.php', $screen(), 'Files result for the typed name');
            $tui->handleInput("\r"); // pick → opens the file
        });
        $this->step(1.0, function () use ($editor): void {
            $this->assertStringEndsWith('Target.php', (string) $editor()->getFilePath(), 'picking a file opens it');
            $this->assertFalse($editor()->isSearchOverlayOpen(), 'picking closes the modal');
        });

        // Reopen; a class name drives the async LSP workspace/symbol source.
        $this->step(1.1, static function () use ($tui): void {
            $tui->handleInput("\x1b[101;6u");
            foreach (str_split('Greeter') as $ch) {
                $tui->handleInput($ch);
            }
        });
        $this->step(1.7, function () use ($screen, $tui): void {
            // after the 0.25s debounce and the mock-LSP round-trip
            $this->assertStringContainsString('Greeter', $screen(), 'Classes/Symbols results from the mock LSP');
            $tui->handleInput("\r"); // jump to the symbol
        });
        $this->step(1.85, function () use ($editor, $tui): void {
            $this->assertStringEndsWith('Target.php', (string) $editor()->getFilePath());
            $this->assertSame(3, $editor()->getDocument()->getCursorLine(), 'jumped to the symbol line');
            $this->assertSame('editor', $tui->getFocus()?->getId(), 'editor refocused after the jump');
        });

        // Reopen, type (which schedules an async debounce), then Esc before it
        // fires → close cancels the pending debounce and the modal disappears.
        $this->step(1.95, function () use ($tui, $editor): void {
            $tui->handleInput("\x1b[101;6u");
            $tui->handleInput('z'); // schedules the async debounce
            $tui->handleInput("\x1b"); // esc → close (cancels the debounce)
            $this->assertFalse($editor()->isSearchOverlayOpen(), 'Esc closes the modal');
            $this->assertSame('editor', $tui->getFocus()?->getId());
        });

        $this->step(2.1, static fn () => $tui->handleInput("\x11")); // ctrl+q

        $watchdog = EventLoop::delay(9.0, static function () use ($tui): void {
            $tui->stop();

            throw new \RuntimeException('watchdog: IDE did not quit before timeout');
        });

        $this->ide->run();
        EventLoop::cancel($watchdog);

        $this->assertFalse($tui->isRunning(), 'run() returned after ctrl+q');
    }
}
