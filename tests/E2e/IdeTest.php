<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Overlay\State\OverlayHost;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchRow;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Boots the full IDE against a VirtualTerminal inside a scratch project
 * wired to the mock LSP server, simulates keystrokes and asserts on the
 * rendered screen.
 *
 * The steps run on {@see RunsIde::runSteps()}, the same chained scheduler the
 * rest of the suite uses: the keys ORDER the stages, they are not waits, and a
 * stage that needs an LSP round trip, a subprocess or a paint awaits it. This
 * file booted the Ide itself before that scheduler existed and kept a second
 * copy on absolute `EventLoop::delay()` keys, where a suspended stage did not
 * push the next one out — a later stage could fire underneath it.
 *
 * It stays ONE continuous session on purpose (mago.toml carries the volume
 * pin): the later stages assert on state the earlier ones created.
 *
 * Assertions inside step closures throw through Ide::run() and fail the
 * test naturally; the TUI's finally-block still restores state.
 */
final class IdeTest extends TestCase
{
    use RunsIde;

    private TempProject $project;
    private VirtualTerminal $terminal;
    private Ide $ide;

    protected function setUp(): void
    {
        $this->project = new TempProject(
            [
                'composer.json' => \json_encode(
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
                '.porta.json' => \json_encode([
                    'lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/mock-lsp-server.php',
                    'keymap' => ['duplicate_line' => ['f8']], // prove .porta.json rebinding works
                ]),
            ],
            'porta-e2e-',
        );

        $this->terminal = new VirtualTerminal(columns: 100, rows: 30);
        new OnboardingState($this->project->dir)->markWelcomeSeen();
        $this->ide = new Ide($this->project->dir, $this->project->path('src/Demo.php'), $this->terminal);
    }

    private function screen(): string
    {
        // Force a frame before reading, through the shared idiom: a bare tick
        // inside the 60fps cap window paints nothing, and assertions must not
        // depend on the adaptive ticker having fired between steps.
        $this->frame(Services::resolve($this->ide, Tui::class));

        return AnsiUtils::stripAnsiCodes($this->terminal->getOutput());
    }

    /**
     * The barrier AND the claim for a screen needle: await it onto the screen,
     * then assert it is there. Seventeen stages below need exactly this pair,
     * and awaiting an effect is not asserting it.
     */
    private function painted(Tui $tui, string $needle, string $because = ''): void
    {
        $this->awaitPainted($tui, $this->terminal, $needle);
        $this->assertStringContainsString($needle, $this->screen(), $because);
    }

    /**
     * Run the scripted session, guarded: nothing here stops the Tui, the app's
     * own Ctrl+Q does, so a quit that never happens has to be caught.
     *
     * @param array<int, callable> $steps
     */
    private function runScenario(array $steps, Tui $tui): void
    {
        // Longer than any barrier inside a step (await defaults to 10s), so a
        // stage that loses its effect fails with the barrier's NAME rather than
        // with this generic message.
        $watchdog = EventLoop::delay(20.0, static function () use ($tui): never {
            $tui->stop();

            throw new \RuntimeException('watchdog: IDE did not quit before timeout');
        });
        try {
            $this->runSteps($steps, $tui, $this->terminal, $this->ide);
        } finally {
            EventLoop::cancel($watchdog);
        }

        $this->assertFalse($tui->isRunning(), 'run() returned after ctrl+q');
    }

    public function testFullScenario(): void
    {
        $tui = Services::resolve($this->ide, Tui::class);
        $screen = $this->screen(...);
        $editor = static fn () => $tui->getById('editor');
        $path = static fn (): string => (string) $editor()->getFilePath();
        // The barrier AND the claim: awaiting an effect does not assert it, and
        // a stage that only waits has stopped testing anything.
        $opened = function (string $suffix, string $what) use ($path): void {
            $this->await(static fn (): bool => \str_ends_with($path(), $suffix), what: $what);
            $this->assertStringEndsWith($suffix, $path());
        };

        $this->runScenario(
            [
                // --- Startup -----------------------------------------------------
                400 => function () use ($screen, $tui): void {
                    // Not just 'LSP:' — `LSP:starting…` matches that, and the very
                    // next stage sends Ctrl+Space, which a session that has not
                    // initialised drops in silence.
                    $this->awaitLspReady($tui, $this->terminal);
                    $s = $screen();
                    $this->assertStringContainsString('src/', $s, 'file tree shows project dirs');
                    $this->assertStringContainsString('composer.json', $s);
                    $this->assertStringContainsString('class Demo', $s, 'editor shows the opened file');
                    $this->assertStringContainsString('src/Demo.php', $s, 'status bar shows the path');
                    $this->assertStringContainsString('LSP:', $s);
                    $this->assertStringContainsString("\x1b[", $tui->getTerminal()->getOutput(), 'ANSI output emitted');
                },

                // --- Completion (mock server) ------------------------------------
                500 => static function () use ($tui): void {
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x00"); // ctrl+space
                },
                1000 => function () use ($screen, $editor, $tui): void {
                    // The popup opens on the server's answer, not on the keystroke.
                    $this->await(static fn (): bool => $editor()->isCompletionOpen(), what: 'the completion popup to open');
                    $this->assertTrue($editor()->isCompletionOpen());
                    $this->painted($tui, 'strlen');
                    $this->assertStringContainsString('str_replace', $screen());
                },
                1100 => static fn () => $tui->handleInput("\r") /* accept */,
                1400 => function () use ($editor, $tui): void {
                    // Deferred auto-import fetched via completionItem/resolve lands
                    // LAST, so it is the barrier for the whole acceptance.
                    $this->await(
                        static fn (): bool => \str_contains($editor()->getDocument()->getText(), 'use function strlen;'),
                        what: 'the deferred auto-import to land',
                    );
                    $doc = $editor()->getDocument();
                    $this->assertStringContainsString('strlen(', $doc->getText());
                    $this->assertStringContainsString('use function strlen;', $doc->getText());
                    $this->assertFalse($editor()->isCompletionOpen());
                    $this->painted($tui, '✗ 1', because: 'diagnostic from mock server in status bar');
                },

                // --- Save ---------------------------------------------------------
                1500 => static fn () => $tui->handleInput("\x13"),
                1800 => function () use ($tui): void {
                    $file = $this->project->path('src/Demo.php');
                    // The flash and the bytes are two effects: await the SLOWER one.
                    $this->await(
                        static fn (): bool => \str_contains((string) \file_get_contents($file), 'strlen('),
                        what: 'the save to reach disk',
                    );
                    $this->painted($tui, 'Saved src/Demo.php');
                    $this->assertStringContainsString('strlen(', (string) \file_get_contents($file));
                },

                // --- Definition jump + back-nav -----------------------------------
                1850 => static fn () => $tui->handleInput("\x1b[24~"), // F12
                2000 => function () use ($tui, $editor, $opened): void {
                    $opened('Target.php', what: 'the definition jump to open Target.php');
                    $this->assertSame(3, $editor()->getDocument()->cursorLine);
                    $tui->handleInput("\x1b[1;7D"); // ctrl+alt+left -> back
                },
                2100 => static function () use ($tui, $opened): void {
                    $opened('Demo.php', what: 'back-nav to reopen Demo.php');
                    $tui->handleInput("\x14"); // ctrl+t -> tree
                },

                // --- Tree navigation ------------------------------------------------
                2200 => function () use ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[B"); // .porta.json
                    $tui->handleInput("\x1b[B"); // composer.json
                    $tui->handleInput("\r");
                },
                2400 => function () use ($tui, $opened): void {
                    $opened('composer.json', what: 'the tree to open composer.json');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $this->painted($tui, 'acme/demo');
                },

                // --- Buffers: tab bar, cycling, recent files ------------------------
                2450 => function () use ($screen, $tui): void {
                    $s = $screen();
                    $this->assertStringContainsString('Demo.php', $s);
                    $this->assertStringContainsString('Target.php', $s);
                    $tui->handleInput("\x1b[1;5C"); // ctrl+right wraps to Demo.php
                },
                2550 => static function () use ($tui, $opened): void {
                    $opened('Demo.php', what: 'ctrl+right to wrap back to Demo.php');
                    $tui->handleInput("\x05"); // ctrl+e
                },
                2650 => function () use ($editor, $tui): void {
                    $this->painted($tui, 'Recent Files');
                    $this->assertTrue(EditorState::pickerOpen($editor()));
                    $this->assertRecentFilesHintTheirDirectory();
                    $tui->handleInput("\r"); // pick previous (composer.json)
                },
                2750 => static function () use ($opened): void {
                    $opened('composer.json', what: 'the recent-files pick to reopen composer.json');
                },

                // --- Find bar ---------------------------------------------------------
                2800 => function () use ($tui): void {
                    $tui->handleInput("\x06"); // ctrl+f
                    $this->assertSame('find-bar', $tui->getFocus()?->getId());
                    foreach (\str_split('acme') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                2900 => function () use ($screen, $editor, $tui): void {
                    $this->painted($tui, '[1/2]', because: 'smart-case: lowercase query matches acme/demo and Acme\\');
                    $this->assertStringContainsString('┃ acme ', $screen());
                    $doc = $editor()->getDocument();
                    $this->assertStringContainsString('acme', $doc->getLine($doc->cursorLine));
                    $tui->handleInput("\x1b"); // esc
                },
                3000 => function () use ($tui): void {
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'editor refocused after find close');
                    // Ctrl+R opens the find bar with the replace field (openFindReplace).
                    $tui->handleInput("\x12"); // ctrl+r
                    $this->assertSame('find-bar', $tui->getFocus()?->getId());
                    $this->painted($tui, '╭ Replace ');
                    $tui->handleInput("\x1b"); // esc
                },

                // --- Go to file palette (a files-only Search Everywhere, centered) -----
                3050 => function () use ($tui, $editor): void {
                    $tui->handleInput("\x10"); // ctrl+p
                    $this->assertTrue($editor()->isSearchOverlayOpen(), 'Ctrl+P opens the centered go-to-file picker');
                    $this->painted($tui, 'Go to File', because: 'the picker title');
                    foreach (\str_split('target') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                3150 => function () use ($tui): void {
                    $this->painted($tui, 'Target.php');
                    $tui->handleInput("\r");
                },
                3250 => static function () use ($tui, $opened): void {
                    $opened('Target.php', what: 'the go-to-file pick to open Target.php');
                    $tui->handleInput("\x05"); // back to composer.json
                    $tui->handleInput("\r");
                },

                // --- Project search ------------------------------------------------------
                3350 => function () use ($editor, $tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → the Find-in-Files modal
                    $this->assertTrue($editor()->isFindModalOpen(), 'the find modal opened');
                    foreach (\str_split('class Target') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                3750 => function () use ($screen, $tui): void {
                    // The modal's live preview groups matches under a file header,
                    // behind a debounce and a search subprocess.
                    $this->painted($tui, '1 result');
                    $this->assertStringContainsString('src/Target.php', $screen(), 'grouped under its file header');
                    $tui->handleInput("\r"); // the first match is pre-selected → jump
                },
                3850 => function () use ($editor, $tui, $opened): void {
                    $opened('Target.php', what: 'the search hit to open Target.php');
                    $this->assertSame(3, $editor()->getDocument()->cursorLine);
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },

                // --- Hover quick-doc --------------------------------------------------------
                3950 => static fn () => $tui->handleInput("\x1b[1;2P"), // F1
                4050 => function () use ($editor, $tui): void {
                    $this->painted($tui, 'greet(): string');
                    $this->assertTrue(EditorState::pickerOpen($editor()));
                    $tui->handleInput("\x1b");
                },

                // --- Go to line ---------------------------------------------------------------
                4150 => static fn () => $tui->handleInput("\x07"), // ctrl+g
                4220 => function () use ($tui): void {
                    $this->painted($tui, 'Go to line');
                    $tui->handleInput('1');
                    $tui->handleInput("\r");
                },
                4300 => function () use ($editor): void {
                    $this->assertSame(0, $editor()->getDocument()->cursorLine);
                },

                // --- Rename via WorkspaceEdit --------------------------------------------------
                4350 => static function () use ($tui): void {
                    $tui->handleInput("\x1b[17;2~"); // shift+f6 -> prepareRename validates, then the prompt opens
                },
                4420 => function () use ($tui): void {
                    // prepareRename is a round trip: the prompt is not up on the keystroke.
                    $this->awaitPainted($tui, $this->terminal, 'Rename to');
                    foreach (\str_split('Renamed') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
                4500 => function () use ($editor, $tui): void {
                    $this->painted($tui, 'Renamed in 1 file');
                    $this->assertSame('class Renamed', $editor()->getDocument()->getLine(3));
                    $tui->handleInput("\x1b[18;3~"); // alt+f7 usages
                },

                // --- Find usages ----------------------------------------------------------------
                4650 => function () use ($screen, $tui): void {
                    $this->painted($tui, '2 results');
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    $this->assertStringContainsString('Usages', $screen());
                    $tui->handleInput("\x1b");
                },

                // --- Signature help ----------------------------------------------------------------
                4750 => static fn () => $tui->handleInput('('),
                4850 => function () use ($tui): void {
                    $this->painted($tui, 'greet(string $name): string');
                    $tui->handleInput("\x05"); // back to composer.json
                    $tui->handleInput("\r");
                },

                // --- Keymap override from .porta.json ----------------------------------------------
                4950 => function () use ($editor, $tui): void {
                    $editor()->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[19~"); // F8, rebound to duplicate_line
                    $doc = $editor()->getDocument();
                    $this->assertSame($doc->getLine(0), $doc->getLine(1), 'config-rebound key duplicates line');
                },

                // --- Quit: dirty warning, then confirm -----------------------------------------------
                5050 => static fn () => $tui->handleInput("\x11"),
                5150 => function () use ($tui): void {
                    $this->painted($tui, 'Unsaved changes');
                    $tui->handleInput("\x11");
                },
            ],
            $tui,
        );
    }

    /** The Recent Files hint is each file's directory — the label already names it — and nothing at the root. */
    private function assertRecentFilesHintTheirDirectory(): void
    {
        $items = Probe::get(Services::resolve($this->ide, OverlayHost::class), 'overlay')['items'];
        $hints = \array_column($items, column_key: 'hint', index_key: 'label');
        $this->assertSame('src', $hints['Target.php'] ?? null, 'the hint is the directory, not the name again');
        $this->assertSame('', $hints['composer.json'] ?? null, 'and nothing at the root');
    }

    /**
     * Search Everywhere: Ctrl+Shift+E opens the centered modal; the empty query
     * seeds recent files; a filename finds a file (sync) and Enter opens it; a
     * class name drives the async LSP workspace/symbol source and Enter jumps to
     * it; typing then Esc cancels the pending debounce and refocuses the editor.
     */
    public function testSearchEverywhere(): void
    {
        $tui = Services::resolve($this->ide, Tui::class);
        $screen = $this->screen(...);
        $editor = static fn () => $tui->getById('editor');

        $this->runScenario(
            [
                // Ctrl+Shift+E → modal opens; the empty query seeds recent files.
                500 => function () use ($screen, $tui, $editor): void {
                    $tui->handleInput("\x1b[101;6u"); // ctrl+shift+e
                    $this->assertTrue($editor()->isSearchOverlayOpen());
                    $this->awaitPainted($tui, $this->terminal, 'Search Everywhere');
                    $s = $screen();
                    $this->assertStringContainsString('Search Everywhere', $s, 'the modal title');
                    $this->assertStringContainsString('Demo.php', $s, 'empty query shows recent files');
                },

                // Type a filename → the Files source lists it (synchronously); Enter opens it.
                700 => static function () use ($tui): void {
                    foreach (\str_split('Target') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                900 => function () use ($tui): void {
                    $this->painted($tui, 'Target.php', because: 'Files result for the typed name');
                    $tui->handleInput("\r"); // pick → opens the file
                },
                1000 => function () use ($editor): void {
                    $this->await(
                        static fn (): bool => \str_ends_with((string) $editor()->getFilePath(), 'Target.php'),
                        what: 'the picked file to open',
                    );
                    $this->assertStringEndsWith('Target.php', (string) $editor()->getFilePath(), 'picking a file opens it');
                    $this->assertFalse($editor()->isSearchOverlayOpen(), 'picking closes the modal');
                },

                // Reopen; a class name drives the async LSP workspace/symbol source.
                1100 => static function () use ($tui): void {
                    $tui->handleInput("\x1b[101;6u");
                    foreach (\str_split('Greeter') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                1700 => function () use ($screen, $tui): void {
                    // Past the 0.25s debounce and the mock-LSP round trip — awaited,
                    // not slept through. The barrier is every category ANSWERED:
                    // not the symbol name, which is also the query echoed in the
                    // input field, and not the 'Classes' header, which shows at
                    // once over "Searching…" while the server is asked.
                    $host = Services::resolve($this->ide, OverlayHost::class);
                    $this->await(
                        static function () use ($host): bool {
                            $rows = Probe::get($host, 'search')->rows ?? [];

                            return (
                                \array_any($rows, static fn (SearchRow $row): bool => SearchCategory::Classes === $row->hit?->category)
                                && !\array_any($rows, static fn (SearchRow $row): bool => null !== $row->note)
                            );
                        },
                        what: 'every async category to answer',
                    );
                    $this->frame($tui);
                    $s = $screen();
                    $this->assertStringContainsString('Classes', $s, 'the async LSP category has hits');
                    $this->assertStringContainsString('Greeter', $s, 'Classes/Symbols results from the mock LSP');
                    $tui->handleInput("\r"); // jump to the symbol
                },
                1850 => function () use ($editor, $tui): void {
                    $this->await(
                        static fn (): bool => 3 === $editor()->getDocument()->cursorLine,
                        what: 'the symbol jump to move the caret',
                    );
                    $this->assertStringEndsWith('Target.php', (string) $editor()->getFilePath());
                    $this->assertSame(3, $editor()->getDocument()->cursorLine, 'jumped to the symbol line');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'editor refocused after the jump');
                },

                // Reopen, type (which schedules an async debounce), then Esc before it
                // fires → close cancels the pending debounce and the modal disappears.
                1950 => function () use ($tui, $editor): void {
                    $tui->handleInput("\x1b[101;6u");
                    $tui->handleInput('z'); // schedules the async debounce
                    $tui->handleInput("\x1b"); // esc → close (cancels the debounce)
                    $this->assertFalse($editor()->isSearchOverlayOpen(), 'Esc closes the modal');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },

                2100 => static fn () => $tui->handleInput("\x11"), // ctrl+q
            ],
            $tui,
        );
    }
}
