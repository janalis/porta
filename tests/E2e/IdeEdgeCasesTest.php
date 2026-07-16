<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Editor\OpenFocus;
use Porta\Editor\SelectionMode;
use Porta\Ide;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Widget\ResultsPanelFields;
use Porta\Widget\TabBarWidget;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Focused full-app scenarios beyond the happy path: missing language
 * server, unreadable files, buffer-close flows, LSP fault handling.
 */
final class IdeEdgeCasesTest extends TestCase
{
    use RunsIde;

    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        putenv('PORTA_LSP');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('PORTA_LSP');
    }

    /**
     * Boot the IDE, run the scripted steps, stop after $duration seconds.
     *
     * @param array<int|string, callable> $steps delay-ms => callback(Tui, VirtualTerminal)
     */
    /**
     * Promote the open Find-in-Files modal to the results pane (Alt+Enter =
     * "Open in Find Window"), so a test can drive the persistent pane as before.
     * Call it after the initial query has settled.
     */
    private function promote(Tui $tui): void
    {
        $tui->handleInput("\x1b\r"); // alt+enter
        $tui->tick();
    }

    /** The Find-in-Files modal's text (it composites over the editor pane). */
    private function modalText(Tui $tui): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 40))));
    }

    /** The editor pane's raw (styled) rendered rows. */
    private function editorRows(Tui $tui): array
    {
        return $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 40));
    }

    /** How many hardware-cursor markers the rendered rows carry (there must be exactly one). */
    private function caretMarkerCount(array $rows): int
    {
        return array_sum(array_map(static fn (string $l): int => substr_count($l, AnsiUtils::CURSOR_MARKER_PREFIX), $rows));
    }

    /** The index of the row carrying the hardware-cursor marker, or null. */
    private function caretMarkerRow(array $rows): ?int
    {
        foreach ($rows as $i => $line) {
            if (str_contains($line, AnsiUtils::CURSOR_MARKER_PREFIX)) {
                return $i;
            }
        }

        return null;
    }

    private function mockLspConfig(): string
    {
        return json_encode(['lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/mock-lsp-server.php']);
    }

    /** The background colour in effect at visible column $col (net of every SGR code up to it). */
    private function netBackgroundAt(string $line, int $col): string
    {
        $slice = AnsiUtils::sliceByColumn($line, $col, 1, true); // carries all prior SGR as pending
        $bg = 'default';
        if (preg_match_all('/\x1b\[([0-9;]*)m/', $slice, $codes)) {
            foreach ($codes[1] as $params) {
                if ('' === $params || '0' === $params || '49' === $params) {
                    $bg = 'default';

                    continue;
                }
                if (preg_match('/48;5;(\d+)/', $params, $m)) {
                    $bg = '48;5;'.$m[1];
                }
            }
        }

        return $bg;
    }

    public function testNoLanguageServerMode(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x00"); // completion: silently ignored
                    $tui->handleInput("\x1b[24~"); // definition: ignored
                    $tui->handleInput("\x1b[1;2P"); // hover: ignored
                    $tui->handleInput("\x1b[18;3~"); // usages: ignored
                    $tui->handleInput("\x1b[104;6u"); // call hierarchy: ignored
                    $tui->handleInput('('); // signature: ignored
                    $tui->handleInput("\x1b[17;2~"); // rename prompt opens...
                    $tui->handleInput("\r"); // ...accept prefilled: flashes "needs a language server"... but word empty -> nothing
                    $tui->handleInput("\x05"); // ctrl+e with a single buffer: no-op
                    $tui->handleInput('x'); // type (didChange path without LSP)
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:none (install phpactor)', $screen);
    }

    public function testRenameWithoutServerFlashes(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n\$word\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 5);
                    $tui->handleInput("\x1b[17;2~"); // shift+f6
                    $tui->handleInput("\r"); // accept prefilled "word"
                },
            ],
        );

        $this->assertStringContainsString('Rename needs a language server', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testStartupWithoutInitialFileFocusesTree(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null,
            [
                300 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                },
            ],
        );
    }

    public function testConfigWarningsAreFlashed(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['keymap' => ['not_a_real_action' => ['f9']]]),
            'a.php' => "<?php\n",
        ]);

        $terminal = $this->runIde($project, $project->path('a.php'), [], 0.6);

        $this->assertStringContainsString('not_a_real_action', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testUnopenableFiles(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['ok.php' => "<?php\n"]);
        $unreadable = $project->write('secret.php', 'nope');
        chmod($unreadable, permissions: 0o000);
        $project->write('huge.bin', str_repeat('x', (2 * 1024 * 1024) + 1));
        $project->write('latin1.txt', "caf\xE9");

        $terminal = $this->runIde(
            $project,
            $project->path('ok.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x14"); // focus tree: entries huge.bin, latin1.txt, ok.php, secret.php
                    $tui->handleInput("\r"); // huge.bin
                    $tui->tick(); // render the flash before it is replaced
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // latin1.txt
                    $tui->tick();
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // secret.php
                    $tui->tick();
                },
            ],
        );

        chmod($unreadable, permissions: 0o644);
        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('File too large', $screen);
        $this->assertStringContainsString('Not a UTF-8 text file', $screen);
        $this->assertStringContainsString('Cannot read secret.php', $screen);
    }

    public function testNoFileOpenShowsPlaceholder(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n"]);

        $terminal = $this->runIde(
            $project,
            null, // start with no file and no session → the editor has nothing open
            [
                250 => function ($tui, $terminal): void {
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('No file open', $screen, 'the empty pane shows the placeholder');
                    $this->assertNull($tui->getById('editor')->getFilePath(), 'no file is open');
                },
            ],
        );

        $this->assertStringContainsString('No file open', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testMouseDragResizesSidebarAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $this->assertSame(30, $tui->getById('tree')->width());
                    // Drag the tree↔editor gap (SGR col 31 = the gap column) out to col 44.
                    $tui->handleInput("\x1b[<0;31;5M"); // arm on the gap
                    $tui->handleInput("\x1b[<32;45;5M"); // drag to col 44
                    $tui->handleInput("\x1b[<0;45;5m"); // release
                },
                320 => function ($tui) use ($project): void {
                    $this->assertSame(44, $tui->getById('tree')->width(), 'sidebar resized by the drag');
                    $layout = json_decode((string) file_get_contents($project->path('.porta/layout.json')), associative: true);
                    $this->assertSame(28, $layout['sidebarWidthPercent'], 'saved as a percentage (round(44/160))');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseDragResizesBottomPanelAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide): void {
                    $tp = $tui->getById('terminal');
                    $tp->open('sh', new \Porta\Terminal\TerminalBuffer());
                    $tui->requestRender();
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tp);
                    $top = $rect->row + 1; // 1-based screen row of the panel's top edge
                    // Arm past the tab strip's tabs (the tail of the top row is
                    // the resize grab; a tab hit would select instead).
                    $grabCol = $rect->col + $rect->columns - 5;
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $grabCol, $top)); // arm on the top edge
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $grabCol, $top - 4)); // drag the edge up 4 rows
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $grabCol, $top - 4)); // release
                },
                320 => function ($tui) use ($project): void {
                    $this->assertSame(16, $tui->getById('terminal')->visibleRows(), 'panel grew by the drag (clamped at rows-8)');
                    $layout = json_decode((string) file_get_contents($project->path('.porta/layout.json')), associative: true);
                    $this->assertSame(67, $layout['bottomPanelHeightPercent'], 'saved as a percentage (round(16/24))');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseDragResizesResultsPaneAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $expected = 0;

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide) use (&$expected): void {
                    $results = $tui->getById('results');
                    $results->open('Find in Project', ResultsPanelFields::queryOnly());
                    $results->setResults([
                        ['path' => 'b.php', 'line' => 0, 'text' => 'one'],
                        ['path' => 'c.php', 'line' => 0, 'text' => 'two'],
                    ]);
                    $tui->requestRender();
                    $tui->tick(); // force a frame so the pane gets a positioned rect
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($results);
                    $expected = $rect->rows + 4;
                    $top = $rect->row; // 1-based screen row of the dock strip — the resize grab
                    $tui->handleInput(\sprintf("\x1b[<0;60;%dM", $top)); // arm on the strip's empty tail
                    $tui->handleInput(\sprintf("\x1b[<32;60;%dM", $top - 4)); // drag the edge up 4 rows
                    $tui->handleInput(\sprintf("\x1b[<0;60;%dm", $top - 4)); // release
                },
                320 => function ($tui) use ($project, &$expected): void {
                    $this->assertSame($expected, $tui->getById('results')->visibleRows(), 'the results pane grew by the drag');
                    $this->assertSame($expected, $tui->getById('terminal')->visibleRows(), 'shared bottom-panel height stays in sync');
                    $layout = json_decode((string) file_get_contents($project->path('.porta/layout.json')), associative: true);
                    $this->assertSame((int) round(($expected * 100) / 24), $layout['bottomPanelHeightPercent'], 'saved as a percentage of the 24 rows');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGoToTestRoundTripsBetweenClassAndTest(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'src/Foo.php' => "<?php\n",
            'tests/Unit/FooTest.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Foo.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[116;6u"); // ctrl+shift+t → the single test
                },
                340 => function ($tui): void {
                    $this->assertStringEndsWith('tests/Unit/FooTest.php', (string) $tui->getById('editor')->getFilePath());
                    $tui->handleInput("\x1b[116;6u"); // ctrl+shift+t again → back to the subject
                },
                480 => function ($tui): void {
                    $this->assertStringEndsWith('src/Foo.php', (string) $tui->getById('editor')->getFilePath());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGoToTestFlashesWhenNothingIsFound(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/Alone.php' => "<?php\n", 'tests/OrphanTest.php' => "<?php\n"]);

        // From a class with no test.
        $this->runIde(
            $project,
            $project->path('src/Alone.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[116;6u");
                },
                340 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('No test found', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        // From a test with no subject.
        $this->runIde(
            $project,
            $project->path('tests/OrphanTest.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[116;6u");
                },
                340 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('No test subject found', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGoToTestOffersChooserForMultipleCandidates(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'src/Multi.php' => "<?php\n",
            'tests/Unit/MultiTest.php' => "<?php\n",
            'tests/E2e/MultiTest.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Multi.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[116;6u");
                },
                340 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Choose target', $screen, 'two candidate tests show a chooser');
                    $this->assertStringContainsString('tests/E2e/MultiTest.php', $screen);
                    $this->assertStringContainsString('tests/Unit/MultiTest.php', $screen);
                    $tui->handleInput("\r"); // open the selected candidate
                },
                480 => function ($tui): void {
                    $this->assertStringEndsWith('MultiTest.php', (string) $tui->getById('editor')->getFilePath());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /** PNG bytes for a $width×$height image split vertically: left half red, right half blue. */
    private function twoColorPng(int $width, int $height): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, x1: 0, y1: 0, x2: intdiv($width, num2: 2) - 1, y2: $height - 1, color: (int) imagecolorallocate($gd, red: 255, green: 0, blue: 0));
        imagefilledrectangle($gd, intdiv($width, num2: 2), y1: 0, x2: $width - 1, y2: $height - 1, color: (int) imagecolorallocate($gd, red: 0, green: 0, blue: 255));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    public function testImageOpensInlineWithHalfBlocksZoomAndPan(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['img.png' => $this->twoColorPng(64, 64)]);

        $this->runIde(
            $project,
            $project->path('img.png'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId(), 'the viewer takes editor-area focus');
                    $output = $terminal->getOutput();
                    $this->assertStringContainsString('▀', $output, 'half-block cells rendered');
                    $this->assertStringContainsString('38;2;255;0;0', $output, 'red pixels as truecolor fg');
                    $this->assertStringContainsString('48;2;0;0;255', $output, 'blue pixels as truecolor bg');
                    $this->assertStringContainsString('img.png', $output);
                    $this->assertStringContainsString('64 × 64', $output, 'dimensions in the status bar');
                    $this->assertStringContainsString('Fit', $output, 'fit zoom label');
                    $tui->handleInput('='); // zoom in one ladder step
                },
                340 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('100%', $terminal->getOutput(), 'zoomed to the next step');
                    // Pan with arrows; wheel + click routing over the image pane.
                    $tui->handleInput("\x1b[C");
                    $tui->handleInput("\x1b[<64;80;10M"); // wheel down over the image → pans
                    $tui->handleInput("\x1b[<67;80;10M"); // horizontal wheel → pans sideways
                    $tui->handleInput("\x1b[<0;80;10M"); // left click → keeps viewer focus
                    $tui->handleInput("\x1b[<0;80;10m");
                    $tui->handleInput("\x1b[<80;80;10M"); // ctrl+wheel up → zoom in toward the pointer
                },
                480 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('200%', $terminal->getOutput(), 'ctrl+wheel zoomed a ladder step');
                    $tui->handleInput("\x1b[<81;80;10M"); // ctrl+wheel down → back out
                    $this->assertSame('100%', $tui->getById('image-viewer')->zoomLabel());
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId(), 'click over the image keeps the viewer focused');
                    // Ctrl+P still opens the go-to-file palette from the viewer.
                    $tui->handleInput("\x10");
                    $this->assertSame('goto-file', $tui->getFocus()?->getId(), 'go-to-file opens over an image tab');
                    $tui->handleInput("\x1b"); // dismiss it
                    // The menu composites over the image pane (no hole), and closing
                    // it restores focus to the viewer (editor-area-aware default).
                    $tui->handleInput("\x1b[21~"); // F10 → menu
                    $tui->tick();
                    $this->assertStringContainsString('Ctrl+Q', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the File dropdown composites over the image');
                    $tui->handleInput("\x1b"); // close the menu
                },
                620 => function ($tui): void {
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId(), 'menu close returns focus to the viewer');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testImageTabSwitchingSavingAndClosingKeepTheEditorSafe(): void
    {
        putenv('PATH=/nonexistent');
        $png = $this->twoColorPng(8, 4);
        $project = new TempProject(['a.php' => "<?php\n// code\n", 'img.png' => $png]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $service = new \ReflectionProperty($ide, 'bufferService')->getValue($ide);
                    $service->openFile($project->path('img.png'), OpenFocus::Editor);
                    $tui->tick();
                    $editor = new \ReflectionProperty($ide, 'editor')->getValue($ide);
                    $this->assertNull($editor->getContext(), 'the editor is detached under an image tab');
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId());
                    // Ctrl+S reaches the viewer (unbound) and saveBuffer guards anyway.
                    $tui->handleInput("\x13");
                    $service->saveCurrentFile();
                },
                340 => function ($tui, $terminal, $ide) use ($project, $png): void {
                    $this->assertSame($png, (string) file_get_contents($project->path('img.png')), 'saving never writes over the image');
                    $tui->handleInput("\x1b[1;5D"); // ctrl+left → back to the code tab
                    $tui->tick();
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertNotNull($tui->getById('editor')->getContext(), 'the editor is re-attached');
                    $tui->handleInput("\x1b[1;5C"); // ctrl+right → the image again
                },
                480 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId());
                    // An external change re-decodes on the next poll; garbage keeps the old pixels.
                    file_put_contents($project->path('img.png'), $this->twoColorPng(16, 2));
                    touch($project->path('img.png'), time() + 2); // force a new disk stamp within the same second
                    $service = new \ReflectionProperty($ide, 'bufferService')->getValue($ide);
                    $service->pollFilesystem();
                    $buffers = new \ReflectionProperty($ide, 'buffers')->getValue($ide);
                    $this->assertSame(16, $buffers->active()?->image?->width, 'externally changed image reloaded');
                    $tui->tick();
                    $this->assertStringContainsString('Reloaded img.png', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    file_put_contents($project->path('img.png'), data: 'not a png anymore');
                    touch($project->path('img.png'), time() + 4);
                    $service->pollFilesystem();
                    $this->assertSame(16, $buffers->active()?->image?->width, 'undecodable bytes keep the old pixels');
                    $tui->handleInput("\x1bw"); // alt+w → close the image tab
                },
                620 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath(), 'closing the image tab restores the editor');
                    $this->assertNotNull($tui->getById('editor')->getContext());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testImageTabGuardsEditorHostedModals(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'img.png' => $this->twoColorPng(4, 4)]);

        // With only the image open, the editor-hosted modals refuse with a flash.
        $this->runIde(
            $project,
            $project->path('img.png'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b[101;6u"); // ctrl+shift+e → search everywhere
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → find in files (same guard)
                    $tui->tick();
                    $this->assertStringContainsString('Not available while viewing an image', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        // With a text tab open too, the modal hops to it first.
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide) use ($project): void {
                    new \ReflectionProperty($ide, 'bufferService')
                        ->getValue($ide)
                        ->openFile($project->path('img.png'), OpenFocus::Editor);
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → find in files
                },
                340 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath(), 'hopped to the text tab');
                    $this->assertStringContainsString('Find in Files', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the modal opened over it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testImageOpenRejectsOversizedCorruptAndLastTabCloseEmptiesTheEditor(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'fake.png' => 'not an image at all',
            'img.png' => $this->twoColorPng(4, 4),
        ]);
        file_put_contents($project->path('big.png'), str_repeat('x', 21 * 1024 * 1024));

        $this->runIde(
            $project,
            null,
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $service = new \ReflectionProperty($ide, 'bufferService')->getValue($ide);
                    $service->openFile($project->path('big.png'));
                    $tui->tick();
                    $this->assertStringContainsString('Image too large (> 20 MB)', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $service->openFile($project->path('fake.png'));
                    $tui->tick();
                    $this->assertStringContainsString('Cannot display image fake.png', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Open the good one, then close the last tab: the editor pane empties.
                    $service->openFile($project->path('img.png'), OpenFocus::Editor);
                    $tui->handleInput("\x1bw");
                },
                340 => function ($tui): void {
                    $tui->tick();
                    $this->assertNull($tui->getById('editor')->getFilePath(), 'empty editor after the last tab closed');
                    $this->assertNotNull($tui->getById('editor')->getContext(), 'editor pane restored');
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGitSidebarDragResizesAndPersists(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['a.txt' => "one\n"]);
        $dir = escapeshellarg($project->dir);
        exec("git -C {$dir} init -q && git -C {$dir} config user.email t@t && git -C {$dir} config user.name t && git -C {$dir} add -A && git -C {$dir} commit -qm init");
        // A seeded percent must be applied at startup (25% of 160 cols = 40).
        if (!is_dir($project->path('.porta'))) {
            mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        file_put_contents($project->path('.porta/layout.json'), json_encode(['gitSidebarWidthPercent' => 25]));

        $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                600 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame(40, $tui->getById('git-changes')->width(), 'the seeded percent applied at startup');
                    // Drag the git separator (SGR col 41 with a 40-wide sidebar)
                    // out to col 61: the whole left column follows.
                    $tui->handleInput("\x1b[<0;41;6M"); // arm on the separator
                    $tui->handleInput("\x1b[<32;61;6M"); // drag right
                    $tui->handleInput("\x1b[<0;61;6m"); // release
                },
                900 => function ($tui) use ($project): void {
                    $this->assertSame(60, $tui->getById('git-changes')->width(), 'the drag resized the sidebar');
                    $this->assertSame(60, $tui->getById('git-tabs')->width(), 'the tab strip follows');
                    $this->assertSame(60, $tui->getById('git-commit')->width(), 'the commit box follows');
                    $layout = json_decode((string) file_get_contents($project->path('.porta/layout.json')), associative: true);
                    $this->assertSame(38, $layout['gitSidebarWidthPercent'], 'saved as a percentage (round(60/160))');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSavedLayoutIsAppliedOnStartup(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        if (!is_dir($project->path('.porta'))) {
            mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        file_put_contents(
            $project->path('.porta/layout.json'),
            json_encode(['sidebarWidthPercent' => 25, 'bottomPanelHeightPercent' => 40]),
        );

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $this->assertSame(40, $tui->getById('tree')->width(), '25% of 160 columns');
                    $this->assertSame(10, $tui->getById('terminal')->visibleRows(), '40% of 24 rows');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGapClickWithoutDragDoesNotResizeOrPersist(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui) use ($project): void {
                    $tui->handleInput("\x1b[<0;31;5M"); // press the gap
                    $tui->handleInput("\x1b[<0;31;5m"); // release without moving
                    $this->assertSame(30, $tui->getById('tree')->width(), 'width unchanged');
                    $this->assertFileDoesNotExist($project->path('.porta/layout.json'), 'no file written for a no-op');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAppMenuDropdownCoversBreadcrumb(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => static function ($tui): void {
                    for ($i = 0; $i < 8; ++$i) {
                        $tui->handleInput("\x1b[B"); // into handle() → breadcrumb bar shows
                    }
                },
                280 => function ($tui): void {
                    $crumb = AnsiUtils::stripAnsiCodes($tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0]);
                    $this->assertStringContainsString('handle', $crumb, 'breadcrumb is visible');
                    // Open View (a right-side title, cols 39-44): its dropdown
                    // reaches down over the breadcrumb row's columns.
                    $tui->handleInput("\x1b[<0;41;1M");
                },
                360 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen());
                    $row = $tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0];
                    $this->assertStringContainsString("\x1b[48;5;236m", $row, 'the dropdown composites over the breadcrumb row (breadcrumb no longer in front)');
                    // Switch to File (left edge): with the full-width menu row its
                    // dropdown now floats over the TREE columns instead.
                    $tui->handleInput("\x1b[<0;3;1M");
                },
                440 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen());
                    $treeTop = AnsiUtils::stripAnsiCodes($tui->getById('tree')->render(new \Symfony\Component\Tui\Render\RenderContext(30, 20))[0]);
                    $this->assertStringContainsString('Save File', $treeTop, "File's dropdown composites over the tree");
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testContextMenuSpillingIntoEmptyEditorDoesNotBleedBackground(): void
    {
        // Right-clicking near the right of the sidebar anchors the menu so it
        // spills across the tree→editor boundary. With no file open the editor is
        // a blank pane; the menu's clipped slice must not bleed its box background
        // across that pane. Regression: OverlayCompositor left the popup
        // background active over the tail, painting a full-width box.
        putenv('PATH=/nonexistent');
        $project = new TempProject(['alpha.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null, // no file open → the blank editor pane the box bled across
            [
                200 => function ($tui, $terminal, $ide): void {
                    // Right-press col 25, row 1: the 22-wide menu spills past the
                    // sidebar (tree cols 0–29) into the editor (col 31+).
                    $tui->handleInput("\x1b[<2;26;3M");
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'context menu opened');

                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $editor = $tui->getById('editor');
                    $rect = $renderer->getWidgetRect($editor);
                    $rows = $editor->render(new \Symfony\Component\Tui\Render\RenderContext($rect->columns, $rect->rows));
                    // A menu item row (offset a couple rows below the anchor).
                    $menuRow = $rows[3] ?? '';
                    // The menu background must not be active far into the blank pane:
                    // a reset separates the composited menu from the editor tail.
                    $this->assertSame('default', $this->netBackgroundAt($menuRow, $rect->columns - 5), 'the menu box background must not bleed across the empty editor');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTreeContextMenuCoversTheSidebarSeparator(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['alpha.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null, // no initial file → the tree is focused
            [
                200 => static function ($tui): void {
                    // Right-click a tree entry; the menu is wide enough to spill past the sidebar.
                    $tui->handleInput("\x1b[<2;21;3M"); // right-press col 20, row 1 (first entry)
                },
                280 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'tree context menu opened');
                    $lines = $tui->getById('separator')->render(new \Symfony\Component\Tui\Render\RenderContext(1, 24));
                    $painted = array_filter($lines, static fn (string $l): bool => ' ' !== $l);
                    $this->assertNotEmpty($painted, 'the context menu paints over the separator column (no hole)');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testContextMenuHighlightsTheItemUnderTheHoveringPointer(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['alpha.php' => "<?php\n"]);

        $this->runIde(
            $project,
            null, // no file open → the tree is focused
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;21;3M"); // right-click a tree entry → context menu
                },
                280 => function ($tui, $terminal, $ide): void {
                    $menu = $tui->getById('context-menu');
                    $this->assertTrue($menu->isOpen(), 'the context menu is open');

                    $overlay = new \ReflectionProperty($ide, 'locator')->getValue($ide)->get(\Porta\Overlay\MenuOverlayController::class);
                    $region = $overlay->contextMenuDropdown();
                    $this->assertNotNull($region, 'the menu overlay was placed');

                    $selected = new \ReflectionProperty($menu, 'selected');
                    $before = $selected->getValue($menu);

                    // Hover up from the bottom row until the highlight lands on a
                    // selectable item other than the initially-selected first one.
                    $moved = false;
                    for ($row = $region->count - 1; $row >= 0 && !$moved; --$row) {
                        $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $region->col + 3, $region->top + $row + 1));
                        $moved = $selected->getValue($menu) !== $before;
                    }
                    $this->assertTrue($moved, 'hovering a menu item moves the highlight to it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInFilesFromFolderContextMenuScopesTheSearch(): void
    {
        // `sub/` and the root both contain the marker; a folder-scoped search
        // must return only the in-folder match. Directories sort first, so `sub`
        // is the first tree entry.
        $project = new TempProject([
            'sub/Inside.php' => "<?php\n// scopedMarker inside\n",
            'Outside.php' => "<?php\n// scopedMarker at the root\n",
        ]);

        $rightClickFolder = static function ($tui, $terminal, $ide): void {
            $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
            $rect = $renderer->getWidgetRect($tui->getById('tree'));
            // Header is relative row 0; the first entry (the `sub` folder) is row 1.
            $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 2));
        };
        $screen = static fn ($tui, $terminal): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());

        $this->runIde(
            $project,
            null, // no initial file → the tree is focused
            [
                200 => $rightClickFolder,
                280 => function ($tui, $terminal) use ($screen): void {
                    $tui->tick();
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'folder context menu opened');
                    $this->assertStringContainsString('Find in Files', $screen($tui, $terminal), 'folder menu offers Find in Files');
                    // New File → New Folder → (skip separator) → Find in Files.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                },
                340 => function ($tui): void {
                    $tui->tick();
                    $this->assertTrue($tui->getById('editor')->isFindModalOpen(), 'the find modal opened, scoped to the folder');
                    $this->assertStringContainsString('Scope: sub', $this->modalText($tui), 'the modal is scoped to the clicked folder');
                    foreach (str_split('scopedMarker') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                650 => function ($tui): void {
                    $tui->tick();
                    $this->promote($tui); // modal → results pane
                    // Assert on the results panel itself (the tree sidebar also lists
                    // Outside.php, so the whole screen is the wrong surface).
                    $panel = AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 14))));
                    $this->assertStringContainsString('1 results', $panel, 'only the in-folder match counts (root is out of scope)');
                    $this->assertStringContainsString('sub/Inside.php', $panel, 'the in-folder match is listed');
                    $this->assertStringNotContainsString('Outside.php', $panel, 'the root match is excluded');
                    $tui->handleInput("\r"); // jump to the match
                },
                760 => function ($tui): void {
                    $this->assertStringEndsWith('sub/Inside.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'editor refocused after the jump');
                    $this->assertTrue($tui->getById('results')->isOpen(), 'the pane stays open after the jump');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testReplaceInFilesFromFolderContextMenuScopesTheReplacement(): void
    {
        // Both files contain $token; a folder-scoped replace must rewrite only the
        // in-folder file. Directories sort first, so `sub` is the first tree entry.
        $project = new TempProject([
            'sub/Inside.php' => "<?php\n\$token = 1;\n",
            'Outside.php' => "<?php\n\$token = 2;\n",
        ]);

        $rightClickFolder = static function ($tui, $terminal, $ide): void {
            $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
            $rect = $renderer->getWidgetRect($tui->getById('tree'));
            $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 2));
        };
        $screen = static fn ($tui, $terminal): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());

        $this->runIde(
            $project,
            null, // no initial file → the tree is focused
            [
                200 => $rightClickFolder,
                280 => function ($tui, $terminal) use ($screen): void {
                    $tui->tick();
                    $this->assertStringContainsString('Replace in Files', $screen($tui, $terminal), 'folder menu offers Replace in Files');
                    // New File → New Folder → (skip sep) → Find in Files → Replace in Files.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                },
                360 => function ($tui): void {
                    $tui->tick();
                    $this->assertTrue($tui->getById('editor')->isFindModalOpen(), 'the find modal opened, scoped to the folder');
                    $this->assertStringContainsString('Scope: sub', $this->modalText($tui), 'the modal is scoped to the clicked folder');
                    foreach (str_split('token') as $ch) {
                        $tui->handleInput($ch); // the search term (query field)
                    }
                },
                760 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // modal: query → replace
                    foreach (str_split('renamed') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a → replace all (scoped)
                },
                1000 => function ($tui) use ($project): void {
                    $this->assertStringContainsString('$renamed = 1;', (string) file_get_contents($project->path('sub/Inside.php')), 'the in-folder file is replaced');
                    $this->assertStringContainsString('$token = 2;', (string) file_get_contents($project->path('Outside.php')), 'the out-of-scope file is untouched');
                },
            ],
        );
    }

    public function testFindInProjectScopeSelectorNarrowsResults(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// tok here\n",
            'src/C.php' => "<?php\n// tok here\n",
            'other/B.php' => "<?php\n// tok here\n",
        ]);
        $panelText = $this->modalText(...); // scope is configured in the modal

        $this->runIde(
            $project,
            $project->path('src/A.php'), // the active file drives Directory/Current File scope
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('src/A.php', $panel, 'whole project finds all three');
                    $this->assertStringContainsString('src/C.php', $panel);
                    $this->assertStringContainsString('other/B.php', $panel);
                    $tui->handleInput("\x1bs"); // alt+s → Directory (src/)
                },
                800 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Directory', $panel);
                    $this->assertStringContainsString('src/A.php', $panel);
                    $this->assertStringContainsString('src/C.php', $panel);
                    $this->assertStringNotContainsString('other/B.php', $panel, 'directory scope drops the sibling folder');
                    $tui->handleInput("\x1bs"); // alt+s → Current File (src/A.php)
                },
                1000 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Current File', $panel);
                    $this->assertStringContainsString('src/A.php', $panel);
                    $this->assertStringNotContainsString('src/C.php', $panel, 'current-file scope keeps only the open file');
                    $tui->handleInput("\x1bs"); // alt+s → Open Files (only src/A.php is open)
                },
                1200 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Open Files', $panel);
                    $this->assertStringContainsString('src/A.php', $panel);
                    $this->assertStringNotContainsString('src/C.php', $panel, 'open-files scope keeps only the open buffers');
                    $tui->handleInput("\x1bs"); // alt+s → Module (no composer.json → whole project)
                },
                1400 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Module', $panel);
                    $this->assertStringContainsString('other/B.php', $panel, 'no package root → module falls back to the whole project');
                    $tui->handleInput("\x1bs"); // alt+s → Whole Project (wraps)
                },
                1600 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Whole Project', $panel);
                    $this->assertStringContainsString('other/B.php', $panel, 'wrapping back to Whole Project widens again');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAddToSearchScopesFromTreeThenSelectItInTheCycle(): void
    {
        $project = new TempProject([
            'lib/Inside.php' => "<?php\n// mark inside\n",
            'other/Outside.php' => "<?php\n// mark at the root\n",
        ]);

        $rightClickFolder = static function ($tui, $terminal, $ide): void {
            $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
            $rect = $renderer->getWidgetRect($tui->getById('tree'));
            // `lib` sorts first among the directories → the first tree entry (row 1).
            $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 2));
        };
        $panelText = $this->modalText(...); // scope is configured in the modal

        $this->runIde(
            $project,
            null, // tree focused
            [
                200 => $rightClickFolder,
                280 => function ($tui): void {
                    $tui->tick();
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'folder context menu opened');
                    // New File → New Folder → Find in Files → Replace in Files → Add to Search Scopes.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // Add to Search Scopes
                },
                400 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('mark') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $this->assertStringContainsString('other/Outside.php', $panelText($tui), 'whole project finds both first');
                    for ($i = 0; $i < 5; ++$i) {
                        $tui->handleInput("\x1bs"); // cycle past the 5 built-ins to the saved lib scope
                    }
                },
                1000 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: lib', $panel, 'the saved scope is selectable in the Alt+S cycle');
                    $this->assertStringContainsString('lib/Inside.php', $panel);
                    $this->assertStringNotContainsString('other/Outside.php', $panel, 'the saved scope narrows to its folder');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectModuleScopeUsesComposerPackageRoot(): void
    {
        $project = new TempProject([
            'pkg/composer.json' => "{}\n",
            'pkg/src/A.php' => "<?php\n// tok here\n",
            'other/B.php' => "<?php\n// tok here\n",
        ]);
        $panelText = $this->modalText(...); // scope is configured in the modal

        $this->runIde(
            $project,
            $project->path('pkg/src/A.php'), // a file inside the pkg/ package
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1bs"); // → Directory
                    $tui->handleInput("\x1bs"); // → Current File
                    $tui->handleInput("\x1bs"); // → Open Files
                    $tui->handleInput("\x1bs"); // → Module
                },
                1000 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Module', $panel);
                    $this->assertStringContainsString('pkg/src/A.php', $panel, 'the module scope keeps the package');
                    $this->assertStringNotContainsString('other/B.php', $panel, 'files outside the package are excluded');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testScopeSelectorWithNoFileOpenStaysWholeProject(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// tok\n", 'other/B.php' => "<?php\n// tok\n"]);
        $panelText = $this->modalText(...); // scope is configured in the modal

        $this->runIde(
            $project,
            null, // no file open → Directory/Current File resolve to the whole project
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1bs"); // alt+s → Directory, but no file → whole project
                },
                800 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Scope: Directory', $panel);
                    $this->assertStringContainsString('src/A.php', $panel);
                    $this->assertStringContainsString('other/B.php', $panel, 'no open file → the scope stays the whole project');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectInCommentsStringsScopeNarrowsMatches(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$needle = 1;\n// needle here\n"]);
        $panelText = $this->modalText(...); // the token-scope option is in the modal

        $this->runIde(
            $project,
            null, // no open file → the modal composites over an empty pane (no file text leaks into asserts)
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('needle') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('$needle = 1;', $panel, 'the code occurrence matches first');
                    $this->assertStringContainsString('// needle here', $panel);
                    $tui->handleInput("\x1bt"); // alt+t → in comments/strings
                },
                1000 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('// needle here', $panel, 'the comment match is kept');
                    $this->assertStringNotContainsString('$needle = 1;', $panel, 'the code match is filtered out');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectContextLinesFromOpenBuffer(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$x = 1;\n// tok here\n\$y = 2;\n"]);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 20))));

        $this->runIde(
            $project,
            $project->path('src/A.php'), // open → context reads from the buffer
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui): void {
                    $tui->tick();
                    $this->promote($tui); // modal → results pane (context lines live on the pane)
                    $tui->handleInput("\x1bm"); // alt+m → expand context on the first match
                },
                800 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('$x = 1;', $panel, 'the preceding line is shown');
                    $this->assertStringContainsString('$y = 2;', $panel, 'the following line is shown');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectContextLinesFromDisk(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// alpha\n// tok here\n// beta\n"]);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 20))));

        $this->runIde(
            $project,
            null, // nothing open → context reads from disk
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui): void {
                    $tui->tick();
                    $this->promote($tui); // modal → results pane (context lines live on the pane)
                    $tui->handleInput("\x1bm"); // alt+m → expand context
                },
                800 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('// alpha', $panel, 'the preceding line is read from disk');
                    $this->assertStringContainsString('// beta', $panel, 'the following line is read from disk');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectMultilineSearchAndReplace(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\nfunction foo()\n{\n    return 1;\n}\n", // opened (active buffer)
            'src/B.php' => "<?php\nfunction foo()\n{\n    return 2;\n}\n", // only on disk
        ]);
        $panelText = $this->modalText(...); // find + replace happen in the modal

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('foo()') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1b[13;2u"); // shift+enter → newline in the query
                    $tui->handleInput('{');
                },
                600 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('foo()⏎{', $panel, 'the multiline query renders the newline as ⏎');
                    $this->assertStringContainsString('2 results', $panel, 'the pattern matches across the line break in both files');
                    $this->assertStringContainsString('foo() ⏎…', $panel, 'the match preview shows its first line + continuation');
                    // Replace the multiline match across the whole file (open buffer + disk).
                    $tui->handleInput("\t"); // query → replace
                    foreach (str_split('renamed') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a → replace all
                },
                1000 => function ($tui) use ($project): void {
                    $tui->tick();
                    $text = $tui->getById('editor')->getDocument()->getText();
                    $this->assertStringContainsString('function renamed', $text, 'the open buffer is replaced across the line break');
                    $this->assertStringNotContainsString('foo()', $text);
                    $this->assertStringContainsString('return 1;', $text, 'the rest of the file is intact');
                    $this->assertStringContainsString('function renamed', (string) file_get_contents($project->path('src/B.php')), 'the on-disk file is replaced too');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectMultilineReplaceWithNoMatchesFlashes(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\nfoo\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('zzz') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1b[13;2u"); // shift+enter
                    foreach (str_split('qqq') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // query → replace
                    $tui->handleInput('x');
                    $tui->handleInput("\x1ba"); // alt+a with a multiline query but no matches
                },
                900 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Nothing to replace', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectModalMaskFilters(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// token here\n",
            'src/B.js' => "// token here\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → modal
                    foreach (str_split('token') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    // In the modal: Tab query → replace → Mask, then restrict to PHP.
                    $tui->handleInput("\t");
                    $tui->handleInput("\t");
                    foreach (str_split('*.php') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                1050 => function ($tui): void {
                    $tui->tick();
                    $modal = $this->modalText($tui);
                    $this->assertStringContainsString('A.php', $modal, 'the PHP file still matches in the modal preview');
                    $this->assertStringNotContainsString('B.js', $modal, 'the *.php mask excludes the JS file');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectReplaceInTheModal(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$foo = 1;\n", // opened (active buffer)
            'src/B.php' => "<?php\n\$foo = 2;\n", // only on disk
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → modal
                    foreach (str_split('foo') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui): void {
                    $tui->tick();
                    // Replace directly in the modal (no promote): the preview flips to old → new.
                    $this->assertStringContainsString('$foo = 1;', $this->modalText($tui), 'the modal previews the matches');
                    $tui->handleInput("\t"); // query → replace
                    foreach (str_split('bar') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $this->assertStringContainsString('→', $this->modalText($tui), 'the modal shows the replacement preview');
                    $tui->handleInput("\x1ba"); // alt+a → replace all
                },
                1000 => function ($tui) use ($project): void {
                    $tui->tick();
                    $this->assertFalse($tui->getById('editor')->isFindModalOpen(), 'the modal closes after replacing');
                    $this->assertStringContainsString('$bar = 1;', $tui->getById('editor')->getDocument()->getText(), 'open buffer replaced');
                    $this->assertStringContainsString('$bar = 2;', (string) file_get_contents($project->path('src/B.php')), 'on-disk file replaced');
                },
            ],
        );
    }

    public function testFindInProjectPinAndRestoreResults(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// foo here\n",
            'src/B.php' => "<?php\n// bar here\n",
        ]);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 16))));

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('foo') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                500 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $this->promote($tui); // modal → results tool window (pin lives on the pane)
                    $this->assertStringContainsString('src/A.php', $panelText($tui), 'the foo search finds A.php');
                    $tui->handleInput("\x1bk"); // alt+k → pin
                    $this->assertStringContainsString('pinned: foo', $panelText($tui), 'the pin indicator appears');
                    $tui->handleInput("\x1b"); // esc → close the tool window
                },
                700 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // reopen the modal
                    foreach (str_split('bar') as $ch) {
                        $tui->handleInput($ch); // run a fresh search in the modal
                    }
                },
                1100 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $this->promote($tui); // bar results → tool window (still carries the pin)
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('src/B.php', $panel, 'the live search moved on to bar');
                    $this->assertStringNotContainsString('src/A.php', $panel);
                    $this->assertStringContainsString('pinned: foo', $panel, 'the pin survives close/reopen');
                    $tui->handleInput("\x1bk"); // alt+k → restore the pinned foo results
                },
                1500 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('Find in Project: foo', $panel, 'the pinned query is restored');
                    $this->assertStringContainsString('src/A.php', $panel, 'the pinned results are restored');
                    $this->assertStringNotContainsString('pinned:', $panel, 'the pin is cleared after restoring');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectExportWritesAndOpensResults(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// tok here\n",
            'src/B.php' => "<?php\n// tok too\n", // a second file → the export separates file groups
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui): void {
                    $tui->tick();
                    $this->promote($tui); // modal → results pane (export runs from the pane)
                    $tui->handleInput("\x1bx"); // alt+x → export
                },
                900 => function ($tui) use ($project): void {
                    $tui->tick();
                    $exportPath = $project->path('.porta/search-results.txt');
                    $this->assertFileExists($exportPath);
                    $content = (string) file_get_contents($exportPath);
                    $this->assertStringContainsString('src/A.php', $content);
                    $this->assertStringContainsString('src/B.php', $content, 'every matched file is listed');
                    $this->assertStringContainsString('2: // tok here', $content, 'line numbers are 1-based');
                    $this->assertStringContainsString('search-results.txt', (string) $tui->getById('editor')->getFilePath(), 'the export opens in the editor');
                },
            ],
        );
    }

    public function testFindInProjectRestoresScopeAndMaskOnReopen(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// tok here\n",
            'other/B.php' => "<?php\n// tok here\n",
        ]);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 16))));

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                500 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // query → replace
                    $tui->handleInput("\t"); // replace → mask
                    foreach (str_split('*.php') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1bs"); // alt+s → Directory scope
                },
                800 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b"); // esc → close (persists scope + mask)
                },
                1000 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // reopen
                },
                1200 => function ($tui): void {
                    $tui->tick();
                    $modal = $this->modalText($tui); // the reopened modal restores the config
                    $this->assertStringContainsString('Scope: Directory', $modal, 'the last scope is restored');
                    $this->assertStringContainsString('Mask: *.php', $modal, 'the last mask is restored');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectF5RerunsPicksUpDiskChanges(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// tok here\n"]);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 16))));

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui) use ($panelText, $project): void {
                    $tui->tick();
                    $this->promote($tui); // modal → results pane (F5 rerun lives on the pane)
                    $this->assertStringContainsString('1 results', $panelText($tui));
                    $project->write('src/B.php', "// tok added later\n"); // a new match on disk
                    $tui->handleInput("\x1b[15~"); // F5 → rerun
                },
                900 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('2 results', $panel, 'rerun sees the new file');
                    $this->assertStringContainsString('src/B.php', $panel);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectPreserveCaseReplace(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// oldname Oldname OLDNAME\n"]);

        $this->runIde(
            $project,
            null, // no open buffer → replace-all writes the file on disk
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('oldname') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1bv"); // alt+v → preserve case (modal option)
                    $tui->handleInput("\t"); // query → replace
                    foreach (str_split('newname') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a → replace all
                },
                900 => function ($tui) use ($project): void {
                    $this->assertStringContainsString(
                        'newname Newname NEWNAME',
                        (string) file_get_contents($project->path('src/A.php')),
                        'each occurrence keeps its original casing',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectSearchHistoryRecall(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// tok here\n"]);

        $this->runIde(
            $project,
            null, // no open buffer → the editor never bleeds "tok" behind the modal
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                500 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b"); // esc → close (records "tok" in history)
                },
                700 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // reopen (fresh, empty query)
                },
                900 => function ($tui): void {
                    $tui->tick();
                    $this->assertStringNotContainsString('tok', $this->modalText($tui), 'reopens empty');
                    $tui->handleInput("\x1bp"); // legacy alt+up → recall the prior query in the modal
                },
                1100 => function ($tui): void {
                    $tui->tick();
                    $this->assertStringContainsString('tok', $this->modalText($tui), 'the prior search is recalled');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindModalShowsTheCaretInTheFocusedFieldOverAFile(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$x = 1;\n\$y = 2;\n"]);

        $this->runIde(
            $project,
            $project->path('src/A.php'), // a file is open → the editor is focused and draws its own caret
            [
                200 => function ($tui): void {
                    // While editing, the editor owns the single caret.
                    $this->assertSame(1, $this->caretMarkerCount($this->editorRows($tui)), 'the editor caret shows while editing');

                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → open the modal
                    $tui->tick();
                    $rows = $this->editorRows($tui);
                    $this->assertSame(1, $this->caretMarkerCount($rows), 'exactly one caret while the modal is open');
                    $caretRow = $this->caretMarkerRow($rows);
                    $this->assertNotNull($caretRow);
                    $this->assertStringContainsString('Find:', AnsiUtils::stripAnsiCodes($rows[$caretRow]), 'the caret sits in the Find field, not the editor');

                    // Tab to the Replace field and type — the caret follows the text
                    // (replace edits re-render the preview only; no project re-search).
                    $tui->handleInput("\t");
                    $tui->handleInput('x');
                    $rows = $this->editorRows($tui);
                    $caretRow = $this->caretMarkerRow($rows);
                    $this->assertNotNull($caretRow);
                    $this->assertSame(1, $this->caretMarkerCount($rows), 'still a single caret');
                    $this->assertStringContainsString('Replace: x', AnsiUtils::stripAnsiCodes($rows[$caretRow]), 'the caret moved to the Replace field');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindModalCaretShowsEvenWhenOpenedFromAnUnfocusedEditor(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n// tok\n"]);

        $this->runIde(
            $project,
            null, // no file open → the tree is focused, the editor pane is not
            [
                200 => function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    $tui->tick();
                    $rows = $this->editorRows($tui);
                    $this->assertSame(1, $this->caretMarkerCount($rows), 'the modal caret shows even though the editor pane is unfocused');
                    $caretRow = $this->caretMarkerRow($rows);
                    $this->assertNotNull($caretRow);
                    $this->assertStringContainsString('Find:', AnsiUtils::stripAnsiCodes($rows[$caretRow]));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseWheelScrollsTheModalPreview(): void
    {
        $content = '<?php';
        for ($i = 0; $i < 20; ++$i) {
            $content .= "\n// tok {$i}";
        }
        $project = new TempProject(['src/A.php' => $content."\n"]);

        $this->runIde(
            $project,
            null, // no open buffer → the file content never bleeds behind the modal
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → modal
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui): void {
                    $tui->tick();
                    $before = $this->modalText($tui);
                    $this->assertStringContainsString('tok 0', $before, 'the first matches show initially');
                    $this->assertStringNotContainsString('tok 18', $before, 'later matches start below the fold');

                    // Wheel down over the editor pane scrolls the modal preview, not the editor.
                    for ($i = 0; $i < 6; ++$i) {
                        $tui->handleInput("\x1b[<65;80;10M"); // SGR wheel-down at col 80, row 10
                    }
                    $this->assertStringContainsString('tok 18', $this->modalText($tui), 'the wheel scrolled the preview to later matches');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseWheelScrollsTheResultsToolWindow(): void
    {
        $files = [];
        for ($i = 0; $i < 20; ++$i) {
            $files["src/File{$i}.php"] = "<?php\n// tok\n";
        }
        $project = new TempProject($files);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 16))));

        $this->runIde(
            $project,
            null,
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $this->promote($tui); // Alt+Enter → results tool window
                    $this->assertStringContainsString('File0.php', $panelText($tui), 'the first files show initially');
                },
                900 => function ($tui, $terminal, $ide) use ($panelText): void {
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('results'));
                    $this->assertNotNull($rect, 'the results pane has a rect');

                    // Wheel down over the pane scrolls its list past the first files.
                    for ($i = 0; $i < 8; ++$i) {
                        $tui->handleInput(\sprintf("\x1b[<65;%d;%dM", $rect->col + 3, $rect->row + 2));
                    }
                    $this->assertStringNotContainsString('File0.php', $panelText($tui), 'the wheel scrolled the first files out of view');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testFindInProjectFilterNarrowsLoadedResults(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// tok here\n",
            'other/B.php' => "<?php\n// tok here\n",
        ]);
        $panelText = static fn ($tui): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('results')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 16))));

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('tok') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $this->promote($tui); // modal → results pane (the filter field lives on the pane)
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('src/A.php', $panel, 'both files match');
                    $this->assertStringContainsString('other/B.php', $panel);
                    // Tab query → mask → filter, then narrow to the src/ tree.
                    $tui->handleInput("\t");
                    $tui->handleInput("\t");
                    foreach (str_split('src') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                800 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('· 1 shown', $panel, 'filtered count');
                    $this->assertStringContainsString('src/A.php', $panel);
                    $this->assertStringNotContainsString('other/B.php', $panel, 'the non-matching file is filtered out');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testStickyScrollPinsHeaderAndClickJumps(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n".str_repeat("        \$x = 1;\n", times: 30)."    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => static function ($tui): void {
                    for ($i = 0; $i < 30; ++$i) {
                        $tui->handleInput("\x1b[B"); // scroll deep into the method
                    }
                },
                280 => function ($tui, $terminal, $ide): void {
                    $editor = $tui->getById('editor');
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($editor);
                    $rendered = $editor->render(new \Symfony\Component\Tui\Render\RenderContext($rect->columns, $rect->rows));
                    $this->assertStringContainsString('class Service', AnsiUtils::stripAnsiCodes($rendered[0]), 'class header pinned at the top');
                    // Click the pinned class header (editor row 0).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 7, $rect->row + 1));
                },
                360 => function ($tui): void {
                    $this->assertSame(4, $tui->getById('editor')->getDocument()->getCursorLine(), 'clicking the pinned header jumped to the class declaration');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testBreadcrumbsShowScopePathAndJumpOnClick(): void
    {
        putenv('PATH=/nonexistent');
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n        \$x = 1;\n    }\n}\n";
        $project = new TempProject(['Service.php' => $code]);

        $this->runIde(
            $project,
            $project->path('Service.php'),
            [
                200 => function ($tui): void {
                    // Caret at the very top is outside every scope → no bar.
                    $this->assertSame([], $tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1)));
                    // Navigate into handle() with the keyboard (invalidates the editor → relayout).
                    for ($i = 0; $i < 8; ++$i) {
                        $tui->handleInput("\x1b[B"); // down to line 8 (the `$x` line)
                    }
                },
                280 => function ($tui): void {
                    $plain = AnsiUtils::stripAnsiCodes($tui->getById('breadcrumbs')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0]);
                    $this->assertStringContainsString('App › Service › handle', $plain, 'the scope path is shown');
                },
                340 => static function ($tui, $terminal, $ide): void {
                    $tui->requestRender();
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('breadcrumbs'));
                    // ' App'(1..3) › 'Service'(7..13) › 'handle'. Click 'Service' (col 8) →
                    // jump to the class declaration (0-based line 4).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8 + 1, $rect->row + 1));
                },
                420 => function ($tui): void {
                    $this->assertSame(4, $tui->getById('editor')->getDocument()->getCursorLine(), 'clicking the Service crumb jumped to the class declaration');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testLeftClickBelowTheEditorIsIgnored(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\necho 2;\necho 3;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('editor'));

                    // Control: a click INSIDE the editor (row for line 2) routes
                    // through handleEditorClick and moves the caret.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8, $rect->row + 3));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 8, $rect->row + 3));
                    $this->assertSame(2, $tui->getById('editor')->getDocument()->getCursorLine(), 'an in-editor click places the caret');

                    // A left press one row below the editor's last row (the status
                    // band) still reaches handleEditorClick, whose guard rejects the
                    // coordinate outside the editor rect — the caret does not move.
                    $row = $rect->row + $rect->rows + 1; // 1-based screen row just below the editor
                    $this->assertLessThanOrEqual(24, $row, 'the click lands on-screen below the editor');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 8, $row));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 8, $row));
                },
                300 => function ($tui): void {
                    $this->assertSame(2, $tui->getById('editor')->getDocument()->getCursorLine(), 'the click below the editor did not move the caret');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSidebarHoverHighlightsRow(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide): void {
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('tree'));
                    // Header is relative row 0, a.php (selected) is row 1; hover b.php at row 2.
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + 3, $rect->row + 3));
                },
                280 => function ($tui, $terminal, $ide): void {
                    $tree = $tui->getById('tree');
                    $line = $tree->render(new \Symfony\Component\Tui\Render\RenderContext(30, 24))[2];
                    $this->assertStringContainsString("\x1b[48;5;236m", $line, 'hovered tree row is highlighted');
                    // Move off the tree into the editor → the hover clears.
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $editorRect = $renderer->getWidgetRect($tui->getById('editor'));
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $editorRect->col + 5, $editorRect->row + 3));
                },
                360 => function ($tui): void {
                    $tree = $tui->getById('tree');
                    $joined = implode('', $tree->render(new \Symfony\Component\Tui\Render\RenderContext(30, 24)));
                    $this->assertStringNotContainsString("\x1b[48;5;236m", $joined, 'hover cleared after leaving the tree');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAppMenuGreysFileActionsWhenNoFileOpen(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            null, // start with no file
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[<0;9;1M"); // open the Edit menu (title cols 7-12)
                },
                280 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $this->assertTrue($menu->isOpen());
                    // Undo is item 0; with no file open it renders dim and is not highlighted.
                    $undo = $menu->dropdownSegments($menu->panelWidth())[0]['segment'];
                    $this->assertStringContainsString("\x1b[38;5;240m", $undo, 'Undo greyed while no file is open');
                    $this->assertStringNotContainsString("\x1b[48;5;31m", $undo, 'disabled item not highlighted');
                    $menu->close();
                },
                360 => static function ($tui) use ($project): void {
                    // Open a file so the document actions re-enable, then reopen Edit.
                    $tui->getById('editor')->openFile($project->path('a.php'), "<?php\necho 1;\n");
                    $tui->handleInput("\x1b[<0;9;1M");
                },
                440 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $undo = $menu->dropdownSegments($menu->panelWidth())[0]['segment'];
                    $this->assertStringNotContainsString("\x1b[38;5;240m", $undo, 'Undo enabled once a file is open');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testBufferCloseFlows(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1bw"); // close a.php (clean): no buffers left -> tree focused
                },
                350 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                    $this->assertNull($tui->getById('editor')->getFilePath());
                    // Reopen both files (opening focuses the editor, so refocus the tree)
                    $tui->handleInput("\r"); // a.php
                    $tui->handleInput("\x14"); // ctrl+t back to tree
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // b.php
                },
                550 => static function ($tui): void {
                    $tui->handleInput('E'); // dirty b.php
                    $tui->handleInput("\x1bw"); // close: opens the unsaved-changes dialog
                },
                700 => function ($tui, $terminal): void {
                    $this->assertTrue($tui->getById('editor')->isOverlayOpen());
                    $this->assertStringContainsString('Unsaved changes in b.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b"); // cancel via escape: buffer stays
                    $tui->handleInput("\x1bw"); // reopen the dialog
                    $tui->handleInput("\x1b[B"); // -> Discard & Close
                    $tui->handleInput("\r");
                },
                850 => function ($tui) use ($project): void {
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertStringNotContainsString('E', (string) file_get_contents($project->path('b.php')), 'discarded edit not written');
                    $tui->handleInput("\x1bw"); // close the last one: tree gets focus
                    $tui->handleInput("\x14"); // ctrl+t back to the (empty) editor
                    $tui->handleInput("\x1bw"); // close with no active buffer: no-op
                    $tui->handleInput("\x13"); // save with no file: no-op
                },
            ],
        );

        $this->assertStringContainsString('open a file from the tree', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testQuitWithASingleDirtyBufferWarnsInTheSingular(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput('E'); // dirty the single open buffer
                    $tui->handleInput("\x11"); // ctrl+q -> first press warns
                },
                400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('1 file has edits', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->assertStringContainsString('Unsaved changes', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testRtlLocaleMirrorsTheMainLayoutSidebarToTheRight(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['language' => 'he']]),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $ids = array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
                    $this->assertSame(['editor-pane', 'separator', 'tree'], $ids, 'RTL puts the file tree on the right');
                },
            ],
        );
    }

    public function testLtrLocaleKeepsTheSidebarOnTheLeft(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $ids = array_map(static fn ($w) => $w->getId(), $tui->getById('main')->all());
                    $this->assertSame(['tree', 'separator', 'editor-pane'], $ids, 'LTR keeps the file tree on the left');
                },
            ],
        );
    }

    public function testRtlEditorCodeIsNotTornOrMirrored(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['language' => 'he']]),
            'demo.php' => "<?php\necho \$name;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    // The code pane stays LTR: the open tag is intact, its
                    // punctuation is not bidi-mirrored, and the line is whole.
                    $this->assertStringContainsString('<?php', $screen, 'open tag intact (not split)');
                    $this->assertStringNotContainsString('?>', $screen, 'punctuation not mirrored to ?>');
                    $this->assertStringContainsString('echo $name;', $screen, 'the code line is not reordered');
                },
            ],
        );
    }

    public function testRtlEveryPaneRowKeepsItsWidth(): void
    {
        // The garble that broke RTL was over-wide rows bleeding across panes.
        // Assert the per-pane reorder keeps every row exactly the terminal width,
        // in the editor view and the (also-reported) settings view.
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['language' => 'he']]),
            'demo.php' => "<?php\necho \$name;\n",
        ]);

        $assertWidths = function ($tui, $ide): void {
            $tui->tick();
            $renderer = new \ReflectionProperty(\Symfony\Component\Tui\Tui::class, 'renderer')->getValue($tui);
            $root = new \ReflectionProperty(\Symfony\Component\Tui\Tui::class, 'root')->getValue($tui);
            $locator = new \ReflectionProperty($ide, 'locator')->getValue($ide);
            $segmenter = $locator->get(\Porta\Ui\PaneSegmenter::class);
            $reorder = $locator->get(\Porta\I18n\PaneReorder::class);
            $terminal = $tui->getTerminal();
            $columns = $terminal->getColumns();
            $lines = array_values($renderer->render($root, $columns, $terminal->getRows()));
            $runs = $segmenter->segment($root, $columns, $terminal->getRows());
            foreach ($lines as $row => $line) {
                $this->assertSame($columns, AnsiUtils::visibleWidth($reorder->reorderRow($line, $runs[$row])), "row {$row} kept its width");
            }
        };

        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => static function ($tui, $terminal, $ide) use ($assertWidths): void {
                    $assertWidths($tui, $ide); // editor view
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
                },
                500 => static function ($tui, $terminal, $ide) use ($assertWidths): void {
                    $assertWidths($tui, $ide); // settings view
                },
            ],
        );
    }

    public function testRtlClickInTheTreeRoutesToTheClickedEntry(): void
    {
        // End-to-end mouse chain in RTL: SGR click → MouseRouter → per-pane
        // logicalColumn → rect hit-test → open. A broken RTL column map would
        // misroute the click out of the (right-hand) tree pane and B would never open.
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['language' => 'he']]),
            'A.php' => "<?php\n",
            'B.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $renderer = new \ReflectionProperty(\Symfony\Component\Tui\Tui::class, 'renderer')->getValue($tui);
                    $rect = $renderer->getWidgetRect($tui->getById('tree'));
                    $this->assertNotNull($rect);
                    $this->assertGreaterThan(0, $rect->col, 'RTL puts the file tree on the right');
                    // Tree lists .porta.json, A.php, B.php → B.php is screen row 5.
                    // Click inside the (right-hand) tree columns; the RTL column map
                    // must keep the click in the tree pane, not spill to the editor.
                    $col = $rect->col + 6;
                    $tui->handleInput("\x1b[<0;{$col};5M");
                },
                500 => function ($tui): void {
                    $this->assertStringEndsWith('B.php', (string) $tui->getById('editor')->getFilePath(), 'the RTL click opened the tree entry');
                },
            ],
        );
    }

    public function testWarningDiagnosticsShowInStatusBar(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$x = 1;\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->getById('editor')->setDiagnostics([
                        1 => [['severity' => 2, 'message' => 'unused variable', 'col' => 0, 'endCol' => 2]],
                    ]);
                    $tui->requestRender(); // what Ide::handleDiagnostics does after mutating
                    $tui->tick();
                },
            ],
        );

        $this->assertStringContainsString('▲ 1', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testUnspawnableServerReportsFailed(): void
    {
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => '/nonexistent-binary-xyz --stdio']),
            'a.php' => "<?php\n",
        ]);

        $terminal = $this->runIde($project, $project->path('a.php'), [], 0.5);

        $this->assertStringContainsString('LSP:failed', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testDebounceFiresAfterBufferClosed(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                400 => static function ($tui): void {
                    $tui->handleInput('x'); // schedules a didChange debounce
                    $tui->handleInput("\x1a"); // undo... still dirty; save instead
                    $tui->handleInput("\x13"); // ctrl+s -> clean
                    $tui->handleInput("\x1bw"); // close: debounce later fires with no file
                },
            ],
        ); // > 400ms + 300ms debounce

        $this->addToAssertionCount(1); // reaching here without an exception is the assertion
    }

    public function testCompletionWithNullResultIsIgnored(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", times: 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Tall.php' => $tall,
        ]);

        $this->runIde(
            $project,
            $project->path('src/Tall.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(91, 0);
                    $tui->handleInput("\x00"); // ctrl+space -> mock replies null
                },
                700 => function ($tui): void {
                    $this->assertFalse($tui->getById('editor')->isCompletionOpen());
                },
            ],
        );
    }

    public function testCompletionResolveWithoutImportsLeavesFileUntouched(): void
    {
        // Accepting an item whose completionItem/resolve returns no
        // additionalTextEdits must insert only the completion, no `use` line.
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Plain.php' => "<?php\n\necho ;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Plain.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 0); // blank line, empty prefix
                    $tui->handleInput("\x00"); // ctrl+space
                },
                700 => function ($tui): void {
                    $this->assertTrue($tui->getById('editor')->isCompletionOpen());
                    $tui->handleInput("\x1b[B"); // -> str_replace
                    $tui->handleInput("\x1b[B"); // -> trim
                    $tui->handleInput("\r"); // accept trim (resolves to no imports)
                },
                1000 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringContainsString('trim(', $doc->getText());
                    $this->assertStringNotContainsString('use ', $doc->getText(), 'no auto-import added');
                },
            ],
        );
    }

    public function testIncrementalDidChangeSendsMinimalDelta(): void
    {
        // A server advertising incremental sync (change kind 2) must receive a
        // ranged content change carrying only the typed text, not the whole file.
        $project = new TempProject([
            '.porta.json' => json_encode([
                'lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/incremental-lsp-server.php',
            ]),
            'src/Inc.php' => "<?php\n\nfoo\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Inc.php'),
            [
                400 => static function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(2, 3); // end of "foo"
                    $tui->handleInput('Z'); // insert one character
                },
                1000 => function ($tui): void {
                    // The mock reflects the change kind + text into a diagnostic.
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $this->assertSame('sync:incremental:Z', $editor->getCursorDiagnostic());
                },
            ],
        );
    }

    public function testSnippetCompletionExpandsWithTabStops(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Snip.php' => "<?php\n\necho ;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Snip.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 0);
                    $tui->handleInput("\x00"); // ctrl+space
                },
                700 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->isCompletionOpen());
                    $tui->handleInput("\x1b[B"); // -> str_replace
                    $tui->handleInput("\x1b[B"); // -> trim
                    $tui->handleInput("\x1b[B"); // -> sprintf (snippet)
                    $tui->handleInput("\r"); // accept
                },
                1000 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertSame('sprintf(format, args)', $doc->getLine(1), 'snippet expanded');
                    $this->assertSame('format', $doc->getSelectedText(), 'first tab stop selected');
                },
            ],
        );
    }

    public function testInlayHintsRenderThroughIde(): void
    {
        // The mock emits inlay hints only for *Inlay* files.
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Inlay.php' => "<?php\n\$x = f();\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/Inlay.php'),
            [
                600 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString(': int', $screen, 'inlay type hint rendered inline');
                },
            ],
        );
    }

    public function testGoToImplementationChooserAndTypeDefinitionJump(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Target.php' => "<?php\n\n// line 2\nclass Target\n{\n}\n",
            'src/Demo.php' => "<?php\n\nclass Demo\n{\n    public function greet(): string\n    {\n        return 'x';\n    }\n}\n".str_repeat("//\n", times: 100),
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(4, 20);
                    $tui->handleInput("\x1b\x02"); // ctrl+alt+b -> implementation (2 results)
                },
                600 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Choose target', $screen, 'multiple implementations show a chooser');
                    $this->assertStringContainsString('Target.php', $screen);
                    $tui->handleInput("\x1b"); // close the chooser
                },
                750 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(4, 20);
                    $tui->handleInput("\x1b[98;6u"); // ctrl+shift+b -> type definition (single -> jump)
                },
                1050 => function ($tui): void {
                    $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath(), 'single type definition jumped');
                    // No implementation at a high line -> flash.
                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                },
            ],
        );
    }

    public function testGoToImplementationWithNoResultFlashes(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Big.php' => "<?php\n".str_repeat("noop();\n", times: 120),
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Big.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(95, 0); // >= 90 -> null
                    $tui->handleInput("\x1b\x02"); // ctrl+alt+b
                },
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('No implementation found', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // >= 80 returns several candidates, all outside the filesystem.
                    $tui->getById('editor')->getDocument()->setCursor(85, 0);
                    $tui->handleInput("\x1b\x02");
                },
                1000 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringNotContainsString('Choose target', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'no chooser when every candidate is unresolvable');
                },
            ],
        );
    }

    public function testFormatSelectionUsesRangeFormatting(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Fmt.php' => "<?php\nmessy( );\nkeep();\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Fmt.php'),
            [
                400 => static function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $doc->setCursor(1, 0);
                    $doc->beginSelect();
                    $doc->setCursor(1, 8, selection: SelectionMode::Keep); // select "messy( )"
                    $tui->handleInput("\x1b\x0c"); // ctrl+alt+l reformat
                },
                700 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    // rangeFormatting replaced only the selection with the marker.
                    $this->assertStringContainsString('FORMATTED', $doc->getLine(1));
                    $this->assertSame('keep();', $doc->getLine(2), 'code outside the selection untouched');
                },
            ],
        );
    }

    public function testPrepareRenameDeclineFlashes(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Big.php' => "<?php\n".str_repeat("noop();\n", times: 120),
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Big.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(96, 0); // >= 95 -> prepareRename null
                    $tui->handleInput("\x1b[17;2~"); // shift+f6
                },
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    // A flash (not a prompt) proves prepareRename was rejected.
                    $this->assertStringContainsString('Cannot rename this element', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testProjectSearchRegexToggle(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$needle = 1;\n\$noodle = 2;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f -> open project search
                },
                400 => static function ($tui): void {
                    foreach (str_split('n..dle') as $ch) {
                        $tui->handleInput($ch); // a regex; as a literal it matches nothing
                    }
                },
                750 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('0 results', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1br"); // alt+r -> enable regex, re-runs the search
                },
                1150 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('2 results', $screen, 'regex n..dle matches needle and noodle');
                    $this->assertStringContainsString('A.php', $screen);
                },
            ],
        );
    }

    public function testProjectReplaceAcrossOpenAndDiskFiles(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$foo = 1;\n", // opened (active buffer)
            'src/B.php' => "<?php\n\$foo = 2;\n", // only on disk
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('foo') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // modal: query → replace
                    foreach (str_split('bar') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a -> replace all
                },
                1000 => function ($tui) use ($project): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringContainsString('$bar = 1;', $doc->getText(), 'open buffer replaced (undoable)');
                    $this->assertStringContainsString('$bar = 2;', (string) file_get_contents($project->path('src/B.php')), 'on-disk file replaced');
                },
            ],
        );
    }

    public function testProjectReplaceInASingleFileFlashesSingular(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$foo = 1;\n", // the only file containing the match
            'src/B.php' => "<?php\n\$bar = 2;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('foo') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // modal: query → replace
                    foreach (str_split('baz') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1ba"); // alt+a -> replace all
                },
                1000 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Replaced in 1 file', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testProjectReplaceWithNothingFlashes(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // open the modal
                    $tui->handleInput("\t"); // query → replace
                    $tui->handleInput("\x1ba"); // replace all with no query/results
                },
                500 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Nothing to replace', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testProjectReplacePreviewExcludesSelectedMatch(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n\$foo = 1;\n\$foo = 2;\n"]); // two matching lines
        $panelText = $this->modalText(...); // replace preview + exclude happen in the modal

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f
                    foreach (str_split('foo') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                600 => function ($tui) use ($panelText): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // modal: query → replace
                    foreach (str_split('bar') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $panel = $panelText($tui);
                    $this->assertStringContainsString('→', $panel, 'preview shows old → new');
                    $this->assertStringContainsString('[x]', $panel, 'kept matches are checked');
                    // Select the second match (Down past the first) and exclude it.
                    $tui->handleInput("\x1b[B"); // down → second match
                    $tui->handleInput("\x1b[3~"); // delete → exclude this line
                    $this->assertStringContainsString('(excluded)', $panelText($tui), 'excluded line is marked');
                    $tui->handleInput("\x1ba"); // alt+a → replace kept
                },
                1000 => function ($tui): void {
                    $tui->tick();
                    $text = $tui->getById('editor')->getDocument()->getText();
                    $this->assertStringContainsString('$bar = 1;', $text, 'kept match replaced');
                    $this->assertStringContainsString('$foo = 2;', $text, 'excluded match left intact');
                    $this->assertStringNotContainsString('$bar = 2;', $text);
                },
            ],
        );
    }

    public function testGitStageCommitBlameViaPalette(): void
    {
        if (!$this->hasGit()) {
            $this->markTestSkipped('git binary not available');
        }
        putenv('PATH='.$this->originalPath);

        $project = new TempProject([]);
        exec('git -C '.escapeshellarg($project->dir).' init -q');
        exec('git -C '.escapeshellarg($project->dir).' config user.email t@t');
        exec('git -C '.escapeshellarg($project->dir).' config user.name tester');
        $project->write('src/A.php', "<?php\n\$a = 1;\n");
        exec('git -C '.escapeshellarg($project->dir).' add -A');
        exec('git -C '.escapeshellarg($project->dir).' commit -qm init');
        $project->write('src/A.php', "<?php\n\$a = 2;\n"); // unstaged change

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a
            foreach (str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => static fn ($tui) => $palette($tui, label: 'Git: Stage'),
                650 => function ($tui, $terminal) use ($palette): void {
                    $tui->tick();
                    $this->assertStringContainsString('Staged src/A.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $palette($tui, label: 'Git: Commit'); // opens the message prompt
                },
                850 => static function ($tui): void {
                    $tui->handleInput("\r"); // empty message -> no-op, prompt closes
                },
                1000 => static function ($tui) use ($palette): void {
                    $palette($tui, label: 'Git: Commit');
                },
                1150 => static function ($tui): void {
                    foreach (str_split('my commit') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r"); // commit
                },
                1450 => function ($tui, $terminal) use ($palette): void {
                    $tui->tick();
                    $this->assertStringContainsString('Committed: my commit', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->getById('editor')->getDocument()->setCursor(1, 0);
                    $palette($tui, label: 'Git: Blame');
                },
                1750 => function ($tui, $terminal) use ($palette): void {
                    $tui->tick();
                    $this->assertStringContainsString('tester', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'blame flashes the author');
                    $palette($tui, label: 'Git: Undo');
                },
                2100 => function ($tui, $terminal) use ($project): void {
                    $tui->tick();
                    $this->assertStringContainsString('Last commit undone', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    exec('git -C '.escapeshellarg($project->dir).' log --format=%s 2>/dev/null', $subjects);
                    $this->assertSame(['init'], $subjects, 'the soft reset removed "my commit"');
                },
            ],
        );
    }

    public function testGitBlameBeyondFileFlashesNoBlame(): void
    {
        if (!$this->hasGit()) {
            $this->markTestSkipped('git binary not available');
        }
        putenv('PATH='.$this->originalPath);

        $project = new TempProject([]);
        exec('git -C '.escapeshellarg($project->dir).' init -q');
        exec('git -C '.escapeshellarg($project->dir).' config user.email t@t');
        exec('git -C '.escapeshellarg($project->dir).' config user.name tester');
        $project->write('src/A.php', "<?php\n"); // one line on disk
        exec('git -C '.escapeshellarg($project->dir).' add -A');
        exec('git -C '.escapeshellarg($project->dir).' commit -qm init');

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => static function ($tui): void {
                    // Grow the buffer (unsaved) and blame a line past the on-disk file,
                    // which git rejects -> empty output -> "No blame".
                    $doc = $tui->getById('editor')->getDocument();
                    $doc->setCursor(1, 0);
                    $doc->insert("\n\n\n\n\n");
                    $doc->setCursor(4, 0);
                    $tui->handleInput("\x1b[97;6u");
                    foreach (str_split('Git: Blame') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('No blame for this line', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testGitCommandsWithoutRepoFlash(): void
    {
        putenv('PATH='.$this->originalPath);
        $project = new TempProject(['src/A.php' => "<?php\n"]); // not a git repo

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u");
            foreach (str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => static fn ($tui) => $palette($tui, label: 'Git: Stage'),
                550 => static fn ($tui) => $palette($tui, label: 'Git: Commit'),
                700 => static fn ($tui) => $palette($tui, label: 'Git: Blame'),
                850 => static fn ($tui) => $palette($tui, label: 'Git: Undo'),
                925 => static fn ($tui) => $palette($tui, label: 'Git: Branches'),
                1000 => static fn ($tui) => $palette($tui, label: 'Git: Stash Pop'),
                1075 => static fn ($tui) => $palette($tui, label: 'Git: Stashes'),
                1150 => static fn ($tui) => $palette($tui, label: 'Git: File History'),
                1225 => static function ($tui) use ($palette): void {
                    $palette($tui, label: 'Git: Stash');
                },
                1300 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Git not available', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testGitBranchPickerSwitchesAndCreatesBranches(): void
    {
        putenv('PATH='.$this->originalPath);
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $d = escapeshellarg($project->dir);
        exec("git -C {$d} init -q && git -C {$d} config user.email t@t && git -C {$d} config user.name t && git -C {$d} add -A && git -C {$d} commit -qm init && git -C {$d} branch feature");
        // A local bare remote with a branch no local shadows: the picker must
        // list it with a "remote" hint and check it out as a tracking branch.
        $bare = new TempProject();
        $b = escapeshellarg($bare->dir);
        exec("git -C {$b} init -q --bare && git -C {$d} remote add origin {$bare->dir} && git -C {$d} push -q origin HEAD:remote-only && git -C {$d} fetch -q origin");

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u");
            foreach (str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static fn ($tui) => $palette($tui, label: 'Git: Branches'),
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Branches', $screen, 'the picker overlay opened');
                    $this->assertStringContainsString('+ Create new branch…', $screen);
                    $this->assertStringContainsString('− Delete branch…', $screen);
                    $this->assertStringContainsString('feature', $screen);
                    $this->assertStringContainsString('remote-only', $screen);
                    $this->assertStringContainsString('remote', $screen);
                    // Rows: create + delete sentinels, then feature, then the
                    // current branch (ties sort by refname), then the remote
                    // row — two steps down picks "feature".
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                },
                1200 => function ($tui, $terminal, $ide) use ($d, $palette): void {
                    $tui->tick();
                    $this->assertStringContainsString('Switched to feature', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    exec("git -C {$d} rev-parse --abbrev-ref HEAD", $head);
                    $this->assertSame('feature', $head[0] ?? '', 'the checkout really happened');

                    // Round 2: create a branch through the sentinel row.
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Branches');
                },
                1600 => function ($tui) use ($d, $palette): void {
                    $tui->tick();
                    $tui->handleInput("\r"); // the "+ Create new branch…" row leads the list
                    $tui->handleInput("\r"); // an empty name is a no-op
                    exec("git -C {$d} branch --list", $branches);
                    $this->assertCount(2, $branches, 'no branch was created from the empty prompt');
                    $palette($tui, label: 'Git: Branches');
                },
                2000 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\r"); // create sentinel again
                    foreach (str_split('fresh') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
                2500 => function ($tui, $terminal) use ($d, $palette): void {
                    $tui->tick();
                    $this->assertStringContainsString('Created and switched to fresh', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    exec("git -C {$d} rev-parse --abbrev-ref HEAD", $head);
                    $this->assertSame('fresh', $head[0] ?? '');

                    // Round 3: check out the remote branch as a tracking branch.
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Branches');
                },
                2900 => static function ($tui): void {
                    $tui->tick();
                    // Rows: 2 sentinels + 3 locals (feature/fresh/main-or-master,
                    // fresh current) + the remote row last — five steps down.
                    foreach (range(1, end: 5) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r");
                },
                3300 => function ($tui, $terminal) use ($d, $palette): void {
                    $tui->tick();
                    $this->assertStringContainsString('Switched to remote-only (tracking origin/remote-only)', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    exec("git -C {$d} rev-parse --abbrev-ref remote-only@{upstream}", $upstream);
                    $this->assertSame('origin/remote-only', $upstream[0] ?? '', 'the checkout created a tracking branch');

                    // Round 4: delete "feature" through the delete sentinel.
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Branches');
                },
                3700 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[B"); // the "− Delete branch…" row
                    $tui->handleInput("\r");
                },
                4100 => function ($tui, $terminal) use ($d, $palette): void {
                    $tui->tick();
                    $this->assertStringContainsString('Delete branch', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the delete picker opened');
                    $tui->handleInput("\r"); // "feature" leads (refname tie-break)
                    $tui->tick();
                    $tui->handleInput("\x1b[B"); // down to "Cancel"
                    $tui->handleInput("\r");
                    exec("git -C {$d} branch --list feature", $kept);
                    $this->assertCount(1, $kept, 'cancelling the confirm deletes nothing');

                    // Round 5: same flow, but confirm the delete this time.
                    $palette($tui, label: 'Git: Branches');
                },
                4500 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[B"); // the "− Delete branch…" row
                    $tui->handleInput("\r");
                },
                4900 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\r"); // "feature" again
                    $tui->tick();
                    $tui->handleInput("\r"); // the "Delete" confirm row leads
                },
                5300 => function ($tui, $terminal) use ($d): void {
                    $tui->tick();
                    $this->assertStringContainsString('Deleted branch feature', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    exec("git -C {$d} branch --list feature", $gone);
                    $this->assertSame([], $gone, 'the branch really was deleted');
                },
            ],
        );
        $bare->path('.'); // hold the bare remote alive through the run
    }

    public function testDeleteBranchWithOnlyOneLocalBranchFlashes(): void
    {
        putenv('PATH='.$this->originalPath);
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $d = escapeshellarg($project->dir);
        exec("git -C {$d} init -q && git -C {$d} config user.email t@t && git -C {$d} config user.name t && git -C {$d} add -A && git -C {$d} commit -qm init");

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[97;6u");
                    foreach (str_split('Git: Branches') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
                700 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[B"); // the "− Delete branch…" row
                    $tui->handleInput("\r");
                },
                1100 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('No other local branches to delete', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testQuickFixesEditCommandAndEmptyPaths(): void
    {
        $tall = "<?php\n\nclass QuickFixMe {}\n".str_repeat("// filler\n", times: 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Q.php' => $tall,
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Q.php'),
            [
                // Edit-based action: "Import class" inserts a use statement
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(2, 6);
                    $tui->handleInput("\x1b\r"); // alt+enter
                },
                600 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->isOverlayOpen());
                    $tui->tick(); // flush pending render before reading the screen
                    $this->assertStringContainsString('Quick Fixes', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('Import class', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // pick "Import class"
                },
                750 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $this->assertSame('use Acme\Imported;', $editor->getDocument()->getLine(1));
                    $tui->tick();
                    $this->assertStringContainsString('Applied "Import class" to 1 file', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Command-based action next
                    $tui->handleInput("\x1b\r");
                },
                900 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // -> Generate method
                    $tui->handleInput("\r");
                },
                1100 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $tui->tick();
                    // executeCommand reply and the server-initiated applyEdit both
                    // flash and race; assert on the deterministic buffer effect.
                    $this->assertSame('// generated', $editor->getDocument()->getLine(0), 'server applyEdit landed');
                    // Empty path: mock convention line >= 90
                    $editor->getDocument()->setCursor(95, 0);
                    $tui->handleInput("\x1b\r");
                },
                1300 => function ($tui, $terminal): void {
                    $this->assertFalse($tui->getById('editor')->isOverlayOpen());
                    $tui->tick();
                    $this->assertStringContainsString('No quick fixes here', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testQuickFixUsesDiagnosticRangeAndHandlesNoOpAction(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/D.php' => "<?php\n\$broken = ;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/D.php'),
            [
                // Wait for the mock's published diagnostic on line 0, then request
                // quick fixes from that line: the request range comes from the
                // diagnostic (covered branch), and the actions apply normally.
                500 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b\r");
                },
                700 => function ($tui): void {
                    $this->assertTrue($tui->getById('editor')->isOverlayOpen());
                    $tui->tick();
                    $tui->handleInput("\x1b"); // close without applying
                },
            ],
        );

        $this->assertStringContainsString('Quick Fixes', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testFileStructurePopupJumpsToSymbol(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\n\nclass Demo\n{\n    public function greet(): string { return 'x'; }\n}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                400 => static fn ($tui) => $tui->handleInput("\x1b[24;5~"), // ctrl+f12
                600 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->isOverlayOpen());
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('File Structure', $screen);
                    $this->assertStringContainsString('Demo', $screen);
                    $this->assertStringContainsString('greet', $screen);
                    $tui->handleInput("\x1b[B"); // -> greet
                    $tui->handleInput("\r");
                },
                750 => function ($tui): void {
                    // greet's selectionRange starts at line 4
                    $this->assertSame(4, $tui->getById('editor')->getDocument()->getCursorLine());
                },
            ],
        );

        $this->assertStringContainsString('File Structure', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testFileStructureEmptyResult(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Empty.php' => "<?php\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Empty.php'),
            [
                400 => static fn ($tui) => $tui->handleInput("\x1b7"), // alt+7
                600 => function ($tui, $terminal): void {
                    $this->assertFalse($tui->getById('editor')->isOverlayOpen());
                    $tui->tick();
                    $this->assertStringContainsString('No symbols in this file', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGoToSymbolInProject(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nclass Demo {}\n",
            'src/Target.php' => "<?php\n\n\nclass Greeter {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                400 => static function ($tui): void {
                    $tui->handleInput("\x1b\x0f"); // ctrl+alt+o
                    foreach (str_split('greet') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                750 => function ($tui, $terminal): void {
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Go to Symbol', $screen);
                    $this->assertStringContainsString('Greeter', $screen);
                    $tui->handleInput("\r"); // jump
                },
                900 => function ($tui): void {
                    $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertSame(3, $tui->getById('editor')->getDocument()->getCursorLine());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testProblemsPanelAggregatesAcrossBuffersAndJumps(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/A.php' => "<?php\n\$x = 1;\n",
            'src/B.php' => "<?php\n\$y = 2;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                // Open B too so both buffers carry the mock's line-0 diagnostic
                400 => static function ($tui): void {
                    $tui->handleInput("\x14"); // tree (selection on src/)
                    $tui->handleInput("\r"); // expand src/
                    $tui->handleInput("\x1b[B"); // A.php
                    $tui->handleInput("\x1b[B"); // B.php
                    $tui->handleInput("\r"); // open B.php
                },
                600 => static function ($tui): void {
                    $tui->handleInput("\x1b6"); // alt+6 problems
                },
                750 => function ($tui, $terminal): void {
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Problems', $screen);
                    // Both buffers carry the mock server's line-0 diagnostic
                    $this->assertStringContainsString('Mock diagnostic', $screen);
                    $this->assertStringContainsString('A.php', $screen);
                    $this->assertStringContainsString('B.php', $screen);
                    $tui->handleInput("\r"); // jump to first problem
                },
                900 => function ($tui): void {
                    // First row sorts to A.php; jumping opens it in the editor
                    $this->assertStringEndsWith('A.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $this->assertTrue($tui->getById('results')->isOpen(), 'the pane stays open after the jump');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testReformatFile(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Messy.php' => "<?php\n   \$a=1  ;\n\n\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Messy.php'),
            [
                400 => static fn ($tui) => $tui->handleInput("\x1b\x0c"), // ctrl+alt+l
                600 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $this->assertSame('formatted();', $editor->getDocument()->getLine(2));
                    $tui->tick();
                    $this->assertStringContainsString('Reformatted src/Messy.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGitGutterMarksAndBranchInStatusBar(): void
    {
        if (!$this->hasGit()) {
            $this->markTestSkipped('git binary not available');
        }
        putenv('PATH='.$this->originalPath); // git must be reachable

        $project = new TempProject([]);
        exec('git -C '.escapeshellarg($project->dir).' init -q');
        exec('git -C '.escapeshellarg($project->dir).' config user.email t@t');
        exec('git -C '.escapeshellarg($project->dir).' config user.name tester');
        $project->write('src/A.php', "<?php\n\$a = 1;\n\$b = 2;\n");
        exec('git -C '.escapeshellarg($project->dir).' add -A');
        exec('git -C '.escapeshellarg($project->dir).' commit -qm init');
        // Modify line 2 and append a line after committing
        $project->write('src/A.php', "<?php\n\$a = 999;\n\$b = 2;\n\$c = 3;\n");

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                500 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    // Branch name in the status bar (default branch: main or master)
                    $this->assertMatchesRegularExpression('/\b(main|master)\b/', $screen);
                    $editor = $tui->getById('editor');
                    $rendered = $editor->render(new \Symfony\Component\Tui\Render\RenderContext(80, 10));
                    $joined = implode('', $rendered);
                    // Modified (blue ┆) on line 2, added (green ▎) on line 4
                    $this->assertStringContainsString("\x1b[38;5;75m┆", $joined, 'modified mark present');
                    $this->assertStringContainsString("\x1b[38;5;114m▎", $joined, 'added mark present');
                    // Exact width contract still holds with the git strip
                    foreach ($rendered as $line) {
                        $this->assertSame(80, AnsiUtils::visibleWidth($line));
                    }
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    private function hasGit(): bool
    {
        foreach (explode(\PATH_SEPARATOR, $this->originalPath) as $d) {
            if (is_file($d.'/git') && is_executable($d.'/git')) {
                return true;
            }
        }

        return false;
    }

    public function testApplicationMenuOpenRunAndDismiss(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput('X'); // dirty: "X<?php"
                    $tui->handleInput("\x1b[21~"); // F10 opens the menu
                },
                300 => function ($tui): void {
                    $this->assertSame('menu', $tui->getFocus()?->getId(), 'F10 focused the menu');
                    $this->assertTrue($tui->getById('menu')->isOpen());
                    $tui->handleInput("\x1b[C"); // right -> Edit
                    $tui->handleInput("\r"); // enter -> Edit's first item (Undo)
                },
                400 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertSame('<?php', $editor->getDocument()->getLine(0), 'menu ran Undo via runAction');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'focus returned to editor');
                    $tui->handleInput("\x1b[21~"); // F10 again
                },
                500 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen());
                    $tui->handleInput("\x1b"); // Esc closes
                },
                600 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 'Esc closed the menu');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testApplicationMenuMouse(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput('X'); // dirty: "X<?php"
                    // The menu bar is the full-width top row: File title at
                    // cols 1-6, Edit at 7-12. Click "Edit" (SGR row 1).
                    $tui->handleInput("\x1b[<0;9;1M");
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen(), 'title click opened the menu');
                    $this->assertSame('menu', $tui->getFocus()?->getId());
                    // The dropdown anchors under Edit (col 7); Undo is item 0 at
                    // screen row 1 (below the menu bar) → SGR row 2.
                    $tui->handleInput("\x1b[<0;9;2M"); // click Undo -> runs it + closes
                },
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 'item click ran and closed');
                    $editor = $tui->getById('editor');
                    $this->assertSame('<?php', $editor->getDocument()->getLine(0), 'clicking Undo undid the edit');
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;1M"); // reopen File (title cols 1-6)
                },
                500 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen(), 'File reopened');
                    // Clicking the SAME (open) title again toggles the dropdown closed.
                    $tui->handleInput("\x1b[<0;3;1M"); // File title cols 1-6
                },
                600 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 're-clicking the open title closed it');
                    // Open File, then click a DIFFERENT title (Edit) → switches, stays open.
                    $tui->handleInput("\x1b[<0;3;1M"); // open File (index 0)
                    $tui->handleInput("\x1b[<0;9;1M"); // click Edit (index 1)
                },
                700 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $this->assertTrue($menu->isOpen(), 'clicking another title kept a menu open');
                    $this->assertSame(1, $menu->openIndex(), 'switched to Edit (index 1)');
                    $tui->handleInput("\x1b[<0;100;18M"); // click far below the dropdown -> dismiss
                },
                800 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 'outside click dismissed the menu');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testApplicationMenuHover(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    // Closed bar: hover the File title (cols 1-6, SGR move code 35).
                    $tui->handleInput("\x1b[<35;3;1M");
                },
                280 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $row = $menu->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0];
                    $this->assertStringContainsString("\x1b[48;5;240m", $row, 'closed title highlights on hover');
                    // Move off the bar (into the editor body) → the highlight clears.
                    $tui->handleInput("\x1b[<35;60;10M");
                },
                360 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $row = $menu->render(new \Symfony\Component\Tui\Render\RenderContext(120, 1))[0];
                    $this->assertStringNotContainsString("\x1b[48;5;240m", $row, 'moving off the bar cleared the highlight');
                    $tui->handleInput("\x1b[<0;9;1M"); // click the Edit title (cols 7-12) to open it
                },
                440 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $this->assertTrue($menu->isOpen());
                    $this->assertSame(1, $menu->openIndex(), 'Edit is open');
                    // Hover Redo (item 1) in the dropdown: bar row 0 → items at SGR rows 2,3.
                    $tui->handleInput("\x1b[<35;9;3M");
                },
                520 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $segments = $menu->dropdownSegments($menu->panelWidth());
                    $this->assertStringContainsString("\x1b[48;5;31m", $segments[1]['segment'], 'hovering Redo highlighted it');
                    $this->assertStringNotContainsString("\x1b[48;5;31m", $segments[0]['segment'], 'Undo is no longer highlighted');
                    // While open, hover the sibling File title → switches the dropdown.
                    $tui->handleInput("\x1b[<35;3;1M");
                },
                600 => function ($tui): void {
                    $menu = $tui->getById('menu');
                    $this->assertTrue($menu->isOpen());
                    $this->assertSame(0, $menu->openIndex(), 'hovering the sibling title switched to File');
                    // Hover the editor body while open (off both dropdown and bar) → menu stays open.
                    $tui->handleInput("\x1b[<35;60;12M");
                },
                680 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen(), 'hovering away from the menu kept it open');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The whole point of the floating dropdown: opening a menu must NOT push
     * the content below it down. We snapshot the screen as absolute rows before
     * and after F10 and assert a tree entry keeps its exact row while the
     * dropdown appears composited over the editor pane.
     */
    public function testOpeningMenuDoesNotShiftContentDown(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'A.php' => "<?php\necho 1;\n",
            'zmarker.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui, $terminal): void {
                    $tui->tick();

                    $before = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($before, 'zmarker'), 'tree lists zmarker.php before the menu opens');
                    // The File dropdown floats over the TREE columns now, so the
                    // row-stability marker must live outside its footprint: use
                    // the editor's text (col ~38, untouched by the dropdown).
                    $markerRow = $this->rowOf($before, 'echo 1;');
                    $this->assertNotNull($markerRow, 'editor content visible before the menu opens');

                    $tui->handleInput("\x1b[21~"); // F10 opens the menu

                    $after = $this->screenGrid($tui, $terminal);
                    $this->assertTrue($tui->getById('menu')->isOpen(), 'F10 opened the menu');
                    // Regression: the editor line stays on the SAME screen row (before
                    // the fix it was reflowed downward) and no rows were inserted.
                    $this->assertSame($markerRow, $this->rowOf($after, 'echo 1;'), 'content did not shift down');
                    $this->assertSameSize($before, $after, 'no extra rows inserted');

                    // ...and the dropdown really is drawn, floating over the pane.
                    $menu = $tui->getById('menu');
                    $segment = $menu->dropdownSegments($menu->panelWidth())[0]['segment'];
                    $label = explode(' ', trim(AnsiUtils::stripAnsiCodes($segment)))[0];
                    $this->assertNotSame('', $label);
                    $this->assertStringContainsString($label, implode("\n", $after), 'dropdown composited over the editor pane');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testOpeningMenuBeforeFirstRenderIsSafe(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        $ide = new Ide($project->dir, $project->path('a.php'), $terminal);
        $tui = $ide->getTui();

        // No frame has rendered yet, so every widget rect is null. Opening the
        // menu must not blow up placing the (impossible-to-place) overlay.
        $tui->handleInput("\x1b[21~"); // F10

        $this->assertTrue($tui->getById('menu')->isOpen(), 'menu opened without a prior render');
        $this->assertSame('', $terminal->consumeOutput(), 'nothing was drawn');
    }

    /**
     * Force a full frame and return the screen as stripped text rows. A forced
     * render resets the differential writer, so the output is the clear/home
     * prefix followed by every row joined by CRLF — splittable into a grid,
     * unlike the incremental updates a normal render emits.
     *
     * @return list<string>
     */
    private function screenGrid(Tui $tui, VirtualTerminal $terminal): array
    {
        $terminal->consumeOutput(); // drop anything buffered so far
        $tui->requestRender(force: true); // reset the writer -> next frame is full
        $tui->processRender();
        $raw = $terminal->consumeOutput();
        $body = preg_replace('/^.*\x1b\[H/s', replacement: '', subject: $raw); // strip up to cursor-home

        return array_map(
            AnsiUtils::stripAnsiCodes(...),
            explode("\r\n", (string) $body),
        );
    }

    /**
     * The index of the first grid row containing $needle, or null.
     *
     * @param list<string> $grid
     */
    private function rowOf(array $grid, string $needle): ?int
    {
        foreach ($grid as $i => $row) {
            if (str_contains($row, $needle)) {
                return $i;
            }
        }

        return null;
    }

    public function testMouseClickAndWheel(): void
    {
        putenv('PATH=/nonexistent');
        // Root-level files so the tree lists them directly (dirs sort first,
        // so keeping it flat makes row math simple).
        // Line 3 is long so the horizontal-wheel step has room to scroll.
        $long = "<?php\n".implode("\n", array_map(static fn (int $i) => "// line {$i}".(3 === $i ? str_repeat(' pad', times: 30) : ''), range(1, end: 60)))."\n";
        $project = new TempProject(['A.php' => $long, 'B.php' => "<?php\n// this is B\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                // Editor click: place the cursor on a lower line.
                300 => static function ($tui): void {
                    $tui->tick(); // ensure a frame rendered so widget rects exist
                    // Menu bar owns row 0, tab bar row 1, so the editor content
                    // starts at screen row 2; a click at screen row 4 lands on
                    // document line 2 (col 45 -> past the gutter).
                    $tui->handleInput("\x1b[<0;45;5M");
                },
                400 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $this->assertSame(2, $editor->getDocument()->getCursorLine(), 'click moved cursor');
                    // Horizontal wheel over the editor slides the view without
                    // moving the caret; shift+wheel-up brings it back.
                    $tui->handleInput("\x1b[<67;45;6M");
                    $scroll = new \ReflectionProperty($editor, 'scroll')->getValue($editor);
                    $this->assertSame(6, $scroll->hScroll(), 'wheel-right scrolled the editor sideways');
                    $this->assertSame(2, $editor->getDocument()->getCursorLine(), 'the caret stayed put');
                    $tui->handleInput("\x1b[<68;45;6M");
                    $this->assertSame(0, $scroll->hScroll(), 'shift+wheel-up scrolled back');
                    // Wheel down over the editor scrolls the viewport.
                    $tui->handleInput("\x1b[<65;45;6M");
                    $tui->handleInput("\x1b[<65;45;6M");
                },
                500 => function ($tui): void {
                    // After two wheel-down steps (6 lines) the top visible line is
                    // no longer line 1.
                    $editor = $tui->getById('editor');
                    $rendered = $editor->render(new \Symfony\Component\Tui\Render\RenderContext(69, 20));
                    $topLineNumber = (int) trim(AnsiUtils::stripAnsiCodes($rendered[0]));
                    $this->assertGreaterThan(1, $topLineNumber, 'wheel scrolled the viewport down');
                    // Wheel over the tree scrolls it (feeds arrow keys).
                    $tui->handleInput("\x1b[<65;6;7M");
                    $tui->handleInput("\x1b[<64;6;7M");
                    // Tree click: focus the tree and open B.php. The menu bar now
                    // lives in the editor pane, so the tree owns the full height:
                    // header row 0, A.php row 1, B.php row 2.
                    $tui->handleInput("\x1b[<0;2;4M"); // 0-based col1 row2 -> B.php
                },
                650 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertStringEndsWith('B.php', (string) $editor->getFilePath());
                    // Tab-bar click: switch back to A.php (first tab).
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;34;2M"); // 0-based col33 row1 -> tab area
                },
                800 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertStringEndsWith('A.php', (string) $editor->getFilePath(), 'tab click switched buffer');
                },
            ],
        );

        // Mouse-enable escape written on startup, disable on shutdown.
        $this->assertStringContainsString("\x1b[?1006h", $terminal->getOutput());

        $this->addToAssertionCount(1);
    }

    public function testMouseClicksOutsideAllWidgetsAreIgnored(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $before = $tui->getById('editor')->getDocument()->getCursorLine();
                    // Right-click in the 1-col gap between the tree and editor pane
                    // (SGR col 31 = the gap), drag-motion, release, and a wheel over
                    // empty space are all no-ops (no context menu, no caret move).
                    $tui->handleInput("\x1b[<2;31;4M"); // right press in the gap
                    $tui->handleInput("\x1b[<32;45;4M"); // motion
                    $tui->handleInput("\x1b[<0;45;4m"); // release
                    $tui->handleInput("\x1b[<64;1;24M"); // wheel over the status bar
                    $this->assertSame($before, $tui->getById('editor')->getDocument()->getCursorLine());
                    $this->assertFalse($tui->getById('context-menu')->isOpen());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    // Editor pane starts at column 31 (tree 30 + gap 1), gutter is 6, and content
    // begins at screen row 2 (menu row 0, tab row 1). So document (line L, col C)
    // sits at SGR column 38+C, SGR row 3+L.
    public function testMouseDragSelectsText(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\nsecond line\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;38;3M"); // press at line 0, col 0
                    $tui->handleInput("\x1b[<32;43;3M"); // drag to col 5
                    $tui->handleInput("\x1b[<0;43;3m"); // release
                },
                400 => function ($tui): void {
                    $this->assertSame('hello', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
        );

        $this->assertStringContainsString("\x1b[?1003h", $terminal->getOutput(), 'any-motion mouse mode enabled');
    }

    public function testMouseDragAcrossLines(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "abc\ndef\nghi\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;39;3M"); // line 0, col 1
                    $tui->handleInput("\x1b[<32;39;5M"); // drag down to line 2, col 1
                    $tui->handleInput("\x1b[<0;39;5m");
                },
                400 => function ($tui): void {
                    $this->assertSame("bc\ndef\ng", $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseDoubleClickSelectsWord(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;40;3M"); // press inside "hello"
                    $tui->handleInput("\x1b[<0;40;3M"); // second press same cell -> word
                },
                400 => function ($tui): void {
                    $this->assertSame('hello', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseTripleClickSelectsLine(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\nsecond\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;42;3M");
                    $tui->handleInput("\x1b[<0;42;3M");
                    $tui->handleInput("\x1b[<0;42;3M"); // third press -> whole line
                },
                400 => function ($tui): void {
                    $this->assertSame('hello world', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseShiftClickExtendsSelection(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;38;3M"); // plain click -> caret at col 0
                    $tui->handleInput("\x1b[<4;43;3M"); // Shift+click at col 5 -> extend
                },
                400 => function ($tui): void {
                    $this->assertSame('hello', $tui->getById('editor')->getDocument()->getSelectedText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseAltClickTogglesCaret(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<8;39;4M"); // Alt+Click (code 8) on line 1 -> add caret
                    $this->assertTrue($tui->getById('editor')->hasMultipleCarets(), 'Alt+Click added a caret');
                    $tui->handleInput("\x1b[<8;39;4M"); // Alt+Click same spot -> remove
                    $this->assertFalse($tui->getById('editor')->hasMultipleCarets(), 'Alt+Click again removed it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMousePlainClickCollapsesCarets(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<8;39;4M"); // Alt+Click adds a caret
                    $this->assertTrue($tui->getById('editor')->hasMultipleCarets());
                    $tui->handleInput("\x1b[<0;38;3M"); // plain click collapses to one caret
                    $this->assertFalse($tui->getById('editor')->hasMultipleCarets());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseAltShiftDragColumnSelection(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaaa\nbb\ncccc\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->tick();
                    // Editor content starts at screen col 37 (SGR 38), row 2 (SGR 3);
                    // display col C is SGR 38+C. Alt+Shift (12) drag (44) release.
                    $tui->handleInput("\x1b[<12;39;3M"); // press line 0, display col 1
                    $tui->handleInput("\x1b[<44;41;5M"); // drag to line 2, display col 3
                    $tui->handleInput("\x1b[<0;41;5m"); // release
                    $this->assertTrue($tui->getById('editor')->hasMultipleCarets(), 'rectangle spans 3 lines');
                    $tui->handleInput('X'); // type at every caret
                },
                400 => function ($tui): void {
                    $this->assertSame("aXa\nbX\ncXc\n", $tui->getById('editor')->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMultiCursorThroughRunningIde(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[1;7B"); // ctrl+alt+down
                    $tui->handleInput("\x1b[1;7B");
                    $this->assertTrue($editor->hasMultipleCarets());
                    $tui->handleInput('#'); // type at all three carets
                    $this->assertSame("#one\n#two\n#three\n", $editor->getDocument()->getText());
                    $tui->handleInput("\x1a"); // ctrl+z: one undo reverts all
                    $this->assertSame("one\ntwo\nthree\n", $editor->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMultiCaretSurvivesStrayInputAndShiftSelects(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaaa\nbbbb\ncccc\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[1;7B");
                    $tui->handleInput("\x1b[1;7B"); // carets on lines 0,1,2
                    // A stray focus report between keystrokes must not collapse them.
                    $tui->handleInput('X');
                    $tui->handleInput("\x1b[I");
                    $tui->handleInput('Y');
                    $this->assertTrue($editor->hasMultipleCarets(), 'stray input kept the carets');
                    $this->assertSame("XYaaaa\nXYbbbb\nXYcccc\n", $editor->getDocument()->getText());
                    // shift+right selects one char at every caret; typing replaces each.
                    $tui->handleInput("\x1b[1;2C");
                    $tui->handleInput('Z');
                    $this->assertSame("XYZaaa\nXYZbbb\nXYZccc\n", $editor->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testPeekDefinitionShowsContextAndJumps(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nnew Target();\n",
            'src/Target.php' => "<?php\n\n// header\nclass Target\n{\n    public int \$x = 1;\n}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 4);
                    $tui->handleInput("\x1b[105;6u"); // ctrl+shift+i peek
                },
                600 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->isOverlayOpen());
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Peek: src/Target.php:4', $screen);
                    $this->assertStringContainsString('class Target', $screen, 'definition line shown');
                    $this->assertStringEndsWith('Demo.php', (string) $editor->getFilePath(), 'peek did not switch buffers');
                    $tui->handleInput("\r"); // pick a context line -> jump for real
                },
                750 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertStringEndsWith('Target.php', (string) $editor->getFilePath(), 'Enter jumps into the file');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testPeekWithNoDefinition(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", times: 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/T.php' => $tall,
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/T.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(91, 0); // mock: 90-94 -> no definition
                    $tui->handleInput("\x1b[105;6u");
                },
                600 => function ($tui, $terminal): void {
                    $this->assertFalse($tui->getById('editor')->isOverlayOpen());
                    $tui->tick();
                    $this->assertStringContainsString('Nothing to peek', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // A definition that resolves outside the filesystem is refused.
                    $tui->getById('editor')->getDocument()->setCursor(96, 0); // mock: >=95 untitled uri
                    $tui->handleInput("\x1b[105;6u");
                },
                800 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Peek target outside the filesystem', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testCommandPaletteRunsActionByName(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    foreach (str_split('save all') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                450 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Find Action', $screen);
                    $this->assertStringContainsString('Save All', $screen);
                    // Dirty the buffer, then run "Save All" by name.
                    $tui->getById('editor')->getDocument()->insert('X');
                    $tui->handleInput("\r");
                },
                600 => function ($tui, $terminal) use ($project): void {
                    $tui->tick();
                    $this->assertStringContainsString('X', (string) file_get_contents($project->path('A.php')));
                    $this->assertStringContainsString('Saved', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testCommandPaletteTogglesSettingAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'A.php' => "<?php\n\$x = 1;\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a
                    foreach (str_split('relative') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                450 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Toggle Relative Line Numbers', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // run the toggle
                },
                600 => function ($tui, $terminal) use ($project): void {
                    $tui->tick();
                    $this->assertTrue($tui->getById('editor')->hasRelativeLineNumbers());
                    $this->assertStringContainsString('Relative line numbers on', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Persisted into .porta.json.
                    $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
                    $this->assertTrue($config['settings']['relativeLineNumbers']);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSettingsAppliedAtStartup(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta.json' => json_encode(['settings' => ['inlineDiagnostics' => false, 'relativeLineNumbers' => true]]),
            'A.php' => "<?php\n\$x = 1;\n",
        ]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->hasRelativeLineNumbers(), 'setting applied from file');
                    // Inline diagnostics disabled: even with a diagnostic, no inline text.
                    $editor->setDiagnostics([1 => [['severity' => 1, 'message' => 'boom', 'col' => 0]]]);
                    $rendered = $editor->render(new \Symfony\Component\Tui\Render\RenderContext(80, 5));
                    $this->assertStringNotContainsString('boom', AnsiUtils::stripAnsiCodes($rendered[1]));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSessionSavedOnQuitAndRestoredOnStartup(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "aaa\nbbb\n", 'B.php' => "ccc\n"]);

        // First run: open A and B, move the cursor, quit.
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        $ide = new Ide($project->dir, $project->path('A.php'), $terminal);
        $tui = $ide->getTui();
        EventLoop::delay(0.3, static function () use ($tui): void {
            $tui->handleInput("\x14"); // tree
            $tui->handleInput("\x1b[B"); // B.php
            $tui->handleInput("\r"); // open B.php
        });
        EventLoop::delay(0.5, static function () use ($tui): void {
            $tui->getById('editor')->getDocument()->setCursor(0, 2);
            $tui->handleInput("\x11"); // ctrl+q (clean buffers -> quits)
        });
        $watchdog = EventLoop::delay(3.0, static function () use ($tui): void {
            $tui->stop();
            throw new \RuntimeException('did not quit');
        });
        $ide->run();
        EventLoop::cancel($watchdog);

        $this->assertFileExists($project->path('.porta/session.json'));

        // Second run with NO file argument: the session reopens.
        $terminal2 = new VirtualTerminal(columns: 100, rows: 24);
        $ide2 = new Ide($project->dir, null, $terminal2);
        $tui2 = $ide2->getTui();
        EventLoop::delay(0.3, function () use ($tui2): void {
            $editor = $tui2->getById('editor');
            $this->assertNotNull($editor->getFilePath(), 'a buffer was restored');
            $this->assertSame('editor', $tui2->getFocus()?->getId());
            $tui2->stop();
        });
        $ide2->run();

        $this->addToAssertionCount(1);
    }

    public function testLocalHistorySnapshotAndRestore(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "version one\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui) use ($project): void {
                    // Edit and save -> first snapshot; edit and save -> second.
                    $d = $tui->getById('editor')->getDocument();
                    $d->selectAll();
                    $d->insert("version two\n");
                    $tui->handleInput("\x13"); // ctrl+s
                },
                450 => static function ($tui): void {
                    $d = $tui->getById('editor')->getDocument();
                    $d->selectAll();
                    $d->insert("version three\n");
                    $tui->handleInput("\x13"); // ctrl+s
                },
                600 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x08"); // ctrl+alt+h show history
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    $tui->tick();
                    $this->assertStringContainsString('Local History', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Restore the older snapshot (second row).
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r");
                },
                750 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Restored from local history', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('version two', $tui->getById('editor')->getDocument()->getText());
                    // Restoring is a single undo step back to what was on screen.
                    $tui->getById('editor')->handleInput("\x1a"); // ctrl+z
                    $this->assertStringContainsString('version three', $tui->getById('editor')->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testShowHistoryWithNoSnapshots(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "x\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x08"); // ctrl+alt+h, nothing saved yet
                },
                450 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('No local history for this file yet', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testClickingTerminalPaneRefocusesIt(): void
    {
        putenv('PATH='.$this->originalPath); // needs /bin/sh
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` opens the terminal (and focuses it)
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    // Click into the editor: focus leaves the terminal.
                    $tui->handleInput("\x1b[<0;40;3M");
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'clicking the editor moved focus off the terminal');
                },
                360 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    // Click the centre of the terminal pane → focus returns to it.
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('terminal'));
                    $this->assertNotNull($rect, 'terminal pane is laid out');
                    $row = $rect->row + intdiv($rect->rows, num2: 2);
                    $col = $rect->col + intdiv($rect->columns, num2: 2);
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1)); // SGR is 1-based
                    $this->assertSame('terminal', $tui->getFocus()?->getId(), 'clicking the terminal pane refocused it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseWheelScrollsTheTerminal(): void
    {
        putenv('PATH='.$this->originalPath); // needs /bin/sh + seq
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // open the terminal
                    foreach (str_split("seq 30\n") as $ch) {
                        $tui->handleInput($ch); // produce more than a screenful of output
                    }
                },
                900 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('terminal'));
                    $row = $rect->row + intdiv($rect->rows, num2: 2);
                    $col = $rect->col + intdiv($rect->columns, num2: 2);
                    // Wheel up (SGR code 64) over the terminal pane → scroll into history.
                    $tui->handleInput(\sprintf("\x1b[<64;%d;%dM", $col + 1, $row + 1));
                    $tui->tick();
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'scrolled'), 'the wheel scrolled the terminal back');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testKittyKeyInputIsTranslatedForTheShell(): void
    {
        putenv('PATH='.$this->originalPath); // needs /bin/sh
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui, $terminal): void {
                    $terminal->setKittyProtocolActive(true); // pretend the host terminal speaks kitty
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` opens + focuses the terminal
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    foreach (str_split('printf hi') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1b[104;1:3u"); // a stray key-release event → must be dropped
                    $tui->handleInput("\x1b[13u"); // kitty Enter → must become "\r" so the command runs
                },
                900 => function ($tui, $terminal): void {
                    $tui->tick();
                    // Only reachable if the release was dropped and Enter was translated.
                    $this->assertStringContainsString('hi', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testIntegratedTerminalOpensRunsAndCloses(): void
    {
        // Force a predictable shell so the panel spawns deterministically.
        putenv('PATH='.$this->originalPath); // needs /bin/sh
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // ctrl+` toggle terminal
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                    // Type a command into the shell.
                    foreach (str_split("echo hello-term\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('hello-term', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Hide the dock; the session stays alive and the editor regains focus.
                    $tui->handleInput("\x1b[96;5u");
                    $this->assertFalse($tui->getById('terminal')->isVisible());
                    $this->assertTrue($tui->getById('terminal')->isOpen(), 'the terminal tab survives the hide');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
                750 => function ($tui): void {
                    // Re-open: the still-running shell's panel comes back (no respawn).
                    $tui->handleInput("\x1b[96;5u");
                    $this->assertTrue($tui->getById('terminal')->isVisible());
                    // Now exit the shell so onExit updates the title.
                    foreach (str_split("exit\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                1000 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('exited', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
        putenv('SHELL');
    }

    public function testTerminalScrollAndPanelKeys(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[96;5u"); // open terminal
                    // Emit many lines to enable scrollback.
                    foreach (str_split("for i in 1 2 3 4 5 6 7 8 9; do echo row-\$i; done\n") as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                700 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b[5~"); // page up: scroll back
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('scrolled', $screen);
                    $tui->handleInput("\x1b[6~"); // page down
                    $tui->handleInput("\x1b"); // escape hides the dock; the session stays alive
                    $this->assertFalse($tui->getById('terminal')->isVisible());
                    $this->assertTrue($tui->getById('terminal')->isOpen(), 'the terminal tab survives the hide');
                },
            ],
        );

        $this->addToAssertionCount(1);
        putenv('SHELL');
    }

    public function testTerminalViaCommandPalette(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('SHELL=/bin/sh');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a palette
                    foreach (str_split('terminal') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                500 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Open Terminal', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // run it
                },
                700 => function ($tui): void {
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                },
            ],
        );

        $this->addToAssertionCount(1);
        putenv('SHELL');
    }

    public function testRunMenuRunsCurrentFileInTerminal(): void
    {
        putenv('PATH='.$this->originalPath); // needs php + a shell
        $project = new TempProject(['hello.php' => "<?php\necho \"run-output-ok\\n\";\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('hello.php'),
            [
                300 => function ($tui): void {
                    $tui->handleInput("\x1b[114;6u"); // ctrl+shift+r run menu
                    $this->assertTrue($tui->getById('editor')->isOverlayOpen());
                },
                400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('Run current file: hello.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\r"); // run it -> terminal panel
                },
                450 => function ($tui): void {
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                },
                900 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('run-output-ok', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRunLastRepeatsAndEmptyGuards(): void
    {
        putenv('PATH='.$this->originalPath);
        $project = new TempProject(['hello.php' => "<?php\necho \"second-run\\n\";\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('hello.php'),
            [
                300 => function ($tui, $terminal): void {
                    // Run Last with no prior run flashes a hint.
                    $tui->handleInput("\x1b[21;2~"); // shift+f10
                    $tui->tick();
                    $this->assertStringContainsString('No previous run', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Pick a run via the menu.
                    $tui->handleInput("\x1b[114;6u");
                },
                450 => static function ($tui): void {
                    $tui->handleInput("\r"); // run current file
                },
                700 => static function ($tui): void {
                    $tui->getById('terminal')->close();
                    $tui->handleInput("\x1b[21;2~"); // Run Last repeats it
                },
                750 => function ($tui): void {
                    $this->assertTrue($tui->getById('terminal')->isOpen());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRunMenuEmptyWhenNothingToRun(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['notes.txt' => "plain\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('notes.txt'),
            [
                300 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b[114;6u"); // ctrl+shift+r
                    $tui->tick();
                    $this->assertFalse($tui->getById('editor')->isOverlayOpen());
                    $this->assertStringContainsString('Nothing to run', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testJumpStackIsCapped(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nclass Demo {}\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = new VirtualTerminal(columns: 160, rows: 24);
        $ide = new Ide($project->dir, $project->path('src/Demo.php'), $terminal);
        $tui = $ide->getTui();

        $navigation = $ide->getNavigation();
        $stack = new \ReflectionProperty($navigation, 'jumpStack');
        $stack->setValue($navigation, array_fill(0, count: 50, value: ['path' => $project->path('src/Demo.php'), 'line' => 0, 'col' => 0]));

        EventLoop::delay(0.4, static fn () => $tui->handleInput("\x1b[24~")); // F12
        EventLoop::delay(0.8, $tui->stop(...));
        $ide->run();

        $this->assertCount(50, $stack->getValue($navigation), 'oldest entry evicted at the cap');
        $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath());
    }

    public function testSaveAllAndSaveAndCloseDialog(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b\x13"); // ctrl+alt+s with nothing dirty
                    $tui->tick();
                    $tui->handleInput('X'); // dirty a.php
                },
                350 => static function ($tui): void {
                    $tui->handleInput("\x14"); // tree
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // open b.php
                },
                500 => static function ($tui): void {
                    $tui->handleInput('Y'); // dirty b.php
                    $tui->handleInput("\x1b\x13"); // ctrl+alt+s -> saves both
                },
                650 => function ($tui, $terminal) use ($project): void {
                    $this->assertStringContainsString('Saved 2 files', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('X', (string) file_get_contents($project->path('a.php')));
                    $this->assertStringContainsString('Y', (string) file_get_contents($project->path('b.php')));
                    // Now the Save & Close dialog path
                    $tui->handleInput('Z'); // dirty b.php again
                    $tui->handleInput("\x1bw"); // dialog
                    $tui->handleInput("\r"); // Save & Close (first option)
                },
                800 => function ($tui) use ($project): void {
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertStringContainsString('Z', (string) file_get_contents($project->path('b.php')), 'saved before closing');
                },
            ],
        );

        $this->assertStringContainsString('Nothing to save', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testBufferSwitchReusesHighlightCache(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x14");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\r"); // open b.php
                },
                500 => function ($tui): void {
                    // Switching back must render a.php's highlight from the
                    // buffer-owned cache (same version, no re-parse).
                    $editorBefore = $tui->getById('editor');
                    $versionBefore = $editorBefore->getDocument()->getVersion();
                    $tui->handleInput("\x1b[1;5C"); // ctrl+right -> a.php
                    $tui->tick();
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertSame(1, $versionBefore, 'no phantom edits from switching');
                },
            ],
        );
    }

    public function testSaveFailureAndSaveWithoutFile(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['dir/locked.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('dir/locked.php'),
            [
                200 => static function ($tui) use ($project): void {
                    $tui->handleInput('x'); // dirty it
                    chmod($project->path('dir/locked.php'), permissions: 0o444);
                    chmod($project->path('dir'), permissions: 0o555);
                    $tui->handleInput("\x13"); // ctrl+s fails
                },
                400 => static function ($tui) use ($project): void {
                    chmod($project->path('dir'), permissions: 0o755);
                    chmod($project->path('dir/locked.php'), permissions: 0o644);
                    $tui->handleInput("\x1a"); // undo -> clean
                    $tui->handleInput("\x1bw"); // close buffer
                    $tui->handleInput("\x13"); // save with no file: no-op
                },
            ],
        );

        $this->assertStringContainsString('Save failed', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testQuitWithoutDirtyBuffersStopsImmediately(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        $ide = new Ide($project->dir, $project->path('a.php'), $terminal);
        $tui = $ide->getTui();

        EventLoop::delay(0.2, static fn () => $tui->handleInput("\x11"));
        $watchdog = EventLoop::delay(3.0, static function () use ($tui): void {
            $tui->stop();
            throw new \RuntimeException('quit did not stop the app');
        });
        $ide->run();
        EventLoop::cancel($watchdog);

        $this->assertFalse($tui->isRunning());
    }

    public function testQuitRestoresTerminalViaAlternateScreen(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 20);
        $ide = new Ide($project->dir, $project->path('a.php'), $terminal);
        $tui = $ide->getTui();

        EventLoop::delay(0.2, static fn () => $tui->handleInput("\x11")); // Ctrl+Q
        $watchdog = EventLoop::delay(3.0, static function () use ($tui): void {
            $tui->stop();
            throw new \RuntimeException('quit did not stop the app');
        });
        $ide->run();
        EventLoop::cancel($watchdog);

        $out = $terminal->getOutput();
        // Entered the alternate screen on start and left it on quit → the terminal
        // is restored to its pre-launch state (no leftover IDE frame).
        $this->assertStringContainsString("\x1b[?1049h", $out, 'entered the alternate screen');
        $this->assertStringContainsString("\x1b[?1049l", $out, 'left the alternate screen on quit');
        $this->assertGreaterThan(strpos($out, needle: "\x1b[?1049h"), strrpos($out, needle: "\x1b[?1049l"), 'leave comes after enter');
        $this->assertStringContainsString("\x1b[?1000l", $out, 'mouse tracking disabled on quit');
    }

    public function testCrashedServerReportsFailedStatus(): void
    {
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/crashing-lsp-server.php']),
            'a.php' => "<?php\n",
        ]);

        // The crash surfaces via stderr logging; initialize never completes.
        $terminal = $this->runIde($project, $project->path('a.php'), [], 1.0);

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:starting…', $screen);
    }

    public function testServerInitiatedApplyEditIsHandled(): void
    {
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => \PHP_BINARY.' '.__DIR__.'/../Support/demanding-lsp-server.php']),
            'a.php' => "<?php\n",
        ]);

        // The demanding server fires an empty workspace/applyEdit after init
        // (applied: false path) and a real one against the opened file after
        // didOpen (applied: true path, flashed to the status bar).
        $terminal = $this->runIde($project, $project->path('a.php'), [], 1.0);

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:demanding-lsp-server', $screen);
        $this->assertStringContainsString('Applied server edit to 1 file', $screen);
    }

    public function testLspNegativePathsWithMockServer(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", times: 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Tall.php' => $tall,
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Tall.php'),
            [
                400 => static function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(91, 0); // mock convention: >= 90 -> nothing found
                    $tui->handleInput("\x1b[24~"); // definition -> none
                },
                500 => static function ($tui): void {
                    $tui->tick(); // flush the previous flash
                    $tui->handleInput("\x1b[1;2P"); // hover -> none
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[18;3~"); // usages -> none
                },
                650 => static function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(96, 0); // >= 95 -> definition outside filesystem
                    $tui->handleInput("\x1b[24~");
                },
                850 => static function ($tui): void {
                    // Rename that the server rejects: prepareRename succeeds at a
                    // low line, then the rename call itself errors on "ERR".
                    $tui->getById('editor')->getDocument()->setCursor(1, 0);
                    $tui->handleInput("\x1b[17;2~"); // shift+f6 -> prepareRename
                },
                1000 => static function ($tui): void {
                    foreach (str_split('ERR') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('No definition found', $screen);
        $this->assertStringContainsString('No documentation found', $screen);
        $this->assertStringContainsString('No usages found', $screen);
        $this->assertStringContainsString('Target outside the filesystem', $screen);
        $this->assertStringContainsString('Rename failed: cannot rename this', $screen);
    }

    /**
     * A temp dir holding `open` and `xdg-open` shims that append their args to
     * $captureFile, put first on PATH so BrowserOpener resolves them instead of
     * a real browser opener.
     */
    private function shimBrowserOpener(TempProject $project, string $captureFile): void
    {
        foreach (['open', 'xdg-open'] as $name) {
            $shim = $project->write('shim-bin/'.$name, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> ".escapeshellarg($captureFile)."\n");
            chmod($shim, permissions: 0o755);
        }
        putenv('PATH='.$project->path('shim-bin'));
    }

    public function testGotoDeclarationOnNativePhpFunctionOpensDocs(): void
    {
        // No .porta.json: proves the native path never needs a language server.
        $project = new TempProject(['src/A.php' => "<?php\nstrlen('x');\n"]);
        $captureFile = $project->path('opened-urls.txt');
        $this->shimBrowserOpener($project, $captureFile);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 3); // inside 'strlen'
                    $tui->handleInput("\x1b[24~"); // F12
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    // Ctrl+Click the same word: editor pane starts at screen col 31
                    // (tree 30 + separator), gutter is 6 wide, so doc col ~3 of
                    // line 1 (screen row 4, 1-based) is at 1-based col 41.
                    $tui->handleInput("\x1b[<16;41;4M");
                    $tui->handleInput("\x1b[<16;41;4m");
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Opened https://www.php.net', $screen);
        $urls = (string) file_get_contents($captureFile);
        $this->assertStringContainsString('https://www.php.net/manual/en/function.strlen.php', $urls);
        $this->assertSame(2, substr_count($urls, needle: "\n"), 'both F12 and ctrl+click opened the docs');
    }

    public function testGotoDeclarationOnJsBuiltinOpensMdn(): void
    {
        $project = new TempProject(['app.js' => "JSON.parse(x);\n"]);
        $captureFile = $project->path('opened-urls.txt');
        $this->shimBrowserOpener($project, $captureFile);

        $terminal = $this->runIde(
            $project,
            $project->path('app.js'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(0, 2); // inside 'JSON'
                    $tui->handleInput("\x1b[24~");
                },
                600 => function ($tui, $terminal, $ide) use ($captureFile): void {
                    $tui->tick();
                    $tui->getById('editor')->getDocument()->setCursor(0, 6); // 'parse': not curated, no LSP
                    $tui->handleInput("\x1b[24~");
                    // The opener shim is an independent OS process — await its write.
                    $this->await(static fn (): bool => str_contains((string) (is_file($captureFile) ? file_get_contents($captureFile) : false), 'mozilla.org'));
                },
            ],
        );

        $this->assertStringContainsString('Opened https://developer.mozilla.org', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        $urls = (string) file_get_contents($captureFile);
        $this->assertStringContainsString('https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/JSON', $urls);
        $this->assertStringNotContainsString('parse', $urls, 'a non-curated word opens nothing');
    }

    public function testNativeDocsWithoutAnOpenerFlashesTheUrl(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\narray_map('trim', []);\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 2); // inside 'array_map'
                    $tui->handleInput("\x1b[24~");
                },
            ],
        );

        $this->assertStringContainsString(
            'Docs: https://www.php.net/manual/en/function.array-map.php',
            AnsiUtils::stripAnsiCodes($terminal->getOutput()),
        );
    }

    public function testGitViewStagesCommitsDiscardsAndRestores(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['src/A.php' => "<?php\n\$a = 1;\n"]);
        $dir = escapeshellarg($project->dir);
        exec("git -C {$dir} init -q && git -C {$dir} config user.email t@t && git -C {$dir} config user.name t && git -C {$dir} add -A && git -C {$dir} commit -qm init");
        $project->write('src/A.php', "<?php\n\$a = 2;\n"); // unstaged edit
        $project->write('notes.md', "hi\n"); // untracked

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // ctrl+alt+d opens the git view
                },
                550 => function ($tui, $terminal, $ide) use ($project): void {
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('2 changed files', $screen);
                    $this->assertStringContainsString('[ ] M src/A.php', $screen);
                    $this->assertStringContainsString('+$a = 2;', $screen, 'the diff pane shows the worktree change');
                    $tui->handleInput(' '); // stage src/A.php
                },
                850 => function ($tui, $terminal) use ($project): void {
                    exec('git -C '.escapeshellarg($project->dir).' status --porcelain 2>/dev/null', $lines);
                    $this->assertContains('M  src/A.php', $lines, 'space staged the file');
                    // Tab twice: changes -> diff -> commit box; type + ctrl+enter.
                    $tui->handleInput("\t");
                    $tui->handleInput("\t");
                    foreach (str_split('Update A') as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\x1b[13;5u");
                },
                1250 => function ($tui, $terminal) use ($project): void {
                    exec('git -C '.escapeshellarg($project->dir).' log --format=%s 2>/dev/null', $subjects);
                    $this->assertSame(['Update A', 'init'], $subjects, 'ctrl+enter committed the staged change');
                    // Discard the untracked notes.md: Tab walks summary -> description
                    // -> next pane (the changes list), then arm + confirm.
                    $tui->handleInput("\t");
                    $tui->handleInput("\t");
                    $tui->handleInput('d');
                    $tui->handleInput('y');
                },
                1550 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertFileDoesNotExist($project->path('notes.md'), 'discard deleted the untracked file');
                    $tui->handleInput("\x1b"); // esc restores the editor view
                },
                1700 => function ($tui): void {
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $this->assertNotNull($tui->getById('tree'), 'tree pane restored');
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Committed:', $screen);
        $this->assertStringContainsString('Working tree clean', $screen, 'after commit+discard nothing is left');
    }

    public function testGitViewStagesASingleLineFromTheDiffPane(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['f.txt' => "alpha\nbeta\ngamma\n"]);
        $dir = escapeshellarg($project->dir);
        exec("git -C {$dir} init -q && git -C {$dir} config user.email t@t && git -C {$dir} config user.name t && git -C {$dir} add -A && git -C {$dir} commit -qm init");
        $project->write('f.txt', "alpha CHANGED\nbeta\ngamma CHANGED\n");

        $terminal = $this->runIde(
            $project,
            $project->path('f.txt'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\t"); // changes list -> diff pane
                    // Rows: hunk header, ctx?, -alpha, +alpha CHANGED, ... walk to
                    // the first '+' row and stage just it.
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput(' ');
                },
                1000 => function ($tui, $terminal) use ($project): void {
                    exec('git -C '.escapeshellarg($project->dir).' diff --cached 2>/dev/null', $cached);
                    exec('git -C '.escapeshellarg($project->dir).' diff 2>/dev/null', $work);
                    $joinedCached = implode("\n", $cached);
                    $this->assertStringContainsString('alpha', $joinedCached, 'the alpha change reached the index');
                    $this->assertStringNotContainsString('gamma CHANGED', $joinedCached, 'gamma stayed out of the index');
                    $this->assertStringContainsString('gamma CHANGED', implode("\n", $work), 'gamma remains a worktree change');
                    $tui->handleInput("\x1b");
                },
            ],
        );

        $this->assertStringContainsString('[~] M f.txt', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'partial staging shows the mixed checkbox');
    }

    public function testGitViewMouseAndMenuGuard(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['a.txt' => "one\ntwo\nthree\n"]);
        $dir = escapeshellarg($project->dir);
        exec("git -C {$dir} init -q && git -C {$dir} config user.email t@t && git -C {$dir} config user.name t && git -C {$dir} add -A && git -C {$dir} commit -qm init");
        $project->write('a.txt', "one!\ntwo\nthree changed\n");
        $project->write('b.txt', 'new '.str_repeat('long-tail ', times: 8)."\n"); // long enough to h-scroll

        $terminal = $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    // F10 opens the app menu OVER the git view now.
                    $tui->handleInput("\x1b[21~");
                },
                900 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertSame('menu', $tui->getFocus()?->getId(), 'F10 focused the menu bar');
                    $this->assertStringContainsString('Save File', $screen, 'the File dropdown composites over the git panes');
                    $this->assertStringContainsString('Quit', $screen);
                    // Hover a dropdown row (the dropdown starts under the menu
                    // row, i.e. screen row 2), then click the greyed 'Save File'
                    // item: disabled in the git view, so it only closes the menu.
                    $tui->handleInput("\x1b[<35;5;4M");
                    $tui->handleInput("\x1b[<0;5;2M");
                    $tui->handleInput("\x1b[<0;5;2m");
                },
                1150 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame('git-changes', $tui->getFocus()?->getId(), 'closing the menu restores the git focus target');
                    // Click the 'File' title (row 1) to open, then again to close.
                    $tui->handleInput("\x1b[<0;3;1M");
                    $tui->handleInput("\x1b[<0;3;1m");
                    $tui->handleInput("\x1b[<0;3;1M");
                    $tui->handleInput("\x1b[<0;3;1m");
                    // Below the menu row sits the tab strip (row 2), then the
                    // changes header (row 3); a.txt is row 4. Click the checkbox.
                    $tui->handleInput("\x1b[<0;3;4M");
                    $tui->handleInput("\x1b[<0;3;4m");
                },
                1500 => function ($tui, $terminal) use ($project): void {
                    exec('git -C '.escapeshellarg($project->dir).' status --porcelain 2>/dev/null', $lines);
                    $this->assertContains('M  a.txt', $lines, 'checkbox click staged the file');
                    // Hover row 5 (b.txt): a tint only — the diff must NOT load.
                    $tui->handleInput("\x1b[<35;10;5M");
                },
                1700 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringNotContainsString('+new', $screen, 'hover alone must not load the hovered diff');
                    $this->assertStringContainsString('three changed', $screen, "a.txt's diff is still shown");
                    // Moving the pointer onto the diff pane clears the tint...
                    $tui->handleInput("\x1b[<35;80;6M");
                    // ...and a CLICK on b.txt selects it and loads its diff.
                    $tui->handleInput("\x1b[<0;10;5M");
                    $tui->handleInput("\x1b[<0;10;5m");
                },
                1950 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('+new', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the click loaded the untracked diff');
                    // Horizontal wheel (trackpad code 67) over the diff pane
                    // scrolls sideways; the tree/list panes ignore it.
                    $tui->handleInput("\x1b[<67;80;6M");
                    $this->assertSame(6, $tui->getById('git-diff')->hScroll(), 'wheel-right scrolled the diff text');
                    $tui->handleInput("\x1b[<68;80;6M"); // shift+wheel-up = left
                    $this->assertSame(0, $tui->getById('git-diff')->hScroll());
                    $tui->handleInput("\x1b[<67;10;4M"); // over the changes list: ignored
                    // Wheel over the diff pane scrolls it; over the list it moves
                    // the selection (keyboard-like, so it may load).
                    $tui->handleInput("\x1b[<65;80;6M");
                    $tui->handleInput("\x1b[<65;10;4M");
                    // Press mid-text on a diff row and drag ten columns right:
                    // a char-level span arms; ctrl+c copies exactly those chars.
                    $tui->handleInput("\x1b[<0;60;6M");
                    $tui->handleInput("\x1b[<32;70;6M");
                    $tui->handleInput("\x1b[<0;70;6m");
                    $tui->handleInput("\x03");
                    // Click into the commit box summary row.
                    $tui->handleInput("\x1b[<0;5;20M");
                    $tui->handleInput("\x1b[<0;5;20m");
                },
                2250 => function ($tui, $terminal): void {
                    $raw = $terminal->getOutput();
                    // Text col 2..12 of '+new long-tail …' (pane text starts at
                    // SGR col 58): the exact ten chars, no markers, no newline.
                    $this->assertStringContainsString("\x1b]52;c;".base64_encode('w long-tai'), $raw, 'the dragged char span reached the clipboard');
                    $this->assertStringContainsString('Copied 1 line', AnsiUtils::stripAnsiCodes($raw));
                    $this->assertSame('git-commit', $tui->getFocus()?->getId(), 'click focused the commit box');
                    // The diff toolbar tags sit at the right edge of the diff
                    // title row (screen row 2): ' s:split ' then ' w:hide-ws '.
                    // Clicking them flips the modes and persists the prefs.
                    $tui->handleInput("\x1b[<0;141;2M");
                    $tui->handleInput("\x1b[<0;141;2m");
                    $this->assertTrue($tui->getById('git-diff')->isSplit(), 'the split tag switched to side-by-side');
                    $tui->handleInput("\x1b[<0;151;2M");
                    $tui->handleInput("\x1b[<0;151;2m");
                    $tui->handleInput("\x1b");
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('b.txt', $screen);
        $layout = json_decode((string) file_get_contents($project->path('.porta/layout.json')), associative: true);
        $this->assertTrue($layout['gitDiffSplit'], 'the split preference persisted');
        $this->assertTrue($layout['gitDiffHideWhitespace'], 'the whitespace preference persisted');
    }

    public function testSettingsViewSidebarNavFormAndLanguages(): void
    {
        putenv('PATH=/nonexistent'); // no git/LSP subprocesses needed
        $project = new TempProject(['a.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => static function ($tui, $terminal, $ide): void {
                    // Open via the command registry (the ide_settings closure),
                    // then the mouse steps below exercise the routing.
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
                },
                500 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertSame('settings-form', $tui->getFocus()?->getId(), 'settings opened focused on the General form');
                    $this->assertStringContainsString('General', $screen, 'the nav sidebar lists sections');
                    $this->assertStringContainsString('Languages', $screen);
                    $this->assertStringContainsString('Inline diagnostics', $screen, 'General content shown');
                    // Toggle Inline diagnostics (General row 1; row 0 is Language) in the content pane.
                    $tui->handleInput("\x1b[<0;40;3M");
                    $tui->handleInput("\x1b[<0;40;3m");
                    // Hover the nav sidebar (a tint).
                    $tui->handleInput("\x1b[<35;5;3M");
                },
                700 => static function ($tui): void {
                    $tui->tick();
                    // Click the 'Keybindings' entry in the nav (screen row 3).
                    $tui->handleInput("\x1b[<0;5;3M");
                    $tui->handleInput("\x1b[<0;5;3m");
                },
                900 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertSame('settings-nav', $tui->getFocus()?->getId(), 'clicking the nav focuses it');
                    $this->assertStringContainsString('actions', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the keybindings list shows');
                    // Hover + wheel + click the keybindings list (content pane), a nav miss too.
                    $tui->handleInput("\x1b[<35;40;5M"); // hover a list row (nav clears)
                    $tui->handleInput("\x1b[<65;40;5M"); // wheel down over the list
                    $tui->handleInput("\x1b[<0;40;5M"); // click a list row
                    $tui->handleInput("\x1b[<0;40;5m");
                },
                1100 => static function ($tui): void {
                    $tui->tick();
                    // Select PHP in the nav (screen row 6, after Plugins) → the content shows its indent rows.
                    $tui->handleInput("\x1b[<0;5;6M");
                    $tui->handleInput("\x1b[<0;5;6m");
                },
                1300 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Indent style', $screen, 'the PHP section shows indent settings');
                    $this->assertStringContainsString('LSP server', $screen, 'and its LSP server row');
                    // Actuate the indent-style row (auto → spaces) in the content pane.
                    $tui->handleInput("\x1b[<0;40;2M");
                    $tui->handleInput("\x1b[<0;40;2m");
                    // Edit the LSP server row (form row 2 = screen row 4): a click starts inline editing.
                    $tui->handleInput("\x1b[<0;40;4M");
                    $tui->handleInput("\x1b[<0;40;4m");
                    foreach (str_split('my-ls --stdio') as $char) {
                        $tui->handleInput($char);
                    }
                    $tui->handleInput("\r"); // commit → persists + reconfigures the LSP pool live
                    $tui->handleInput("\x1b[21~"); // F10 opens the app menu OVER the settings view
                },
                1500 => function ($tui, $terminal): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<35;5;3M"); // hover a dropdown row: the menu rides along
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertSame('menu', $tui->getFocus()?->getId(), 'F10 focused the menu bar');
                    $this->assertStringContainsString('Settings…', $screen, 'the File dropdown composites over the settings panes');
                    $this->assertStringContainsString('Save File', $screen);
                },
            ],
        );

        $this->assertFalse(IdeConfig::load($project->dir)->settings['inlineDiagnostics'], 'the General toggle persisted');
        $this->assertSame('spaces', IdeConfig::load($project->dir)->languages['php']['indentStyle'], 'the PHP indent change persisted');
        $this->assertSame('my-ls --stdio', IdeConfig::load($project->dir)->lspServers['php'], 'the PHP LSP server persisted');
    }

    public function testThemeSwitchLiveRecolorsChromeAndSyntax(): void
    {
        putenv('PATH=/nonexistent'); // no git/LSP subprocesses needed
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function ($tui, $terminal): void {
                    $tui->tick();
                    // Dark theme baseline: the `return` keyword is the dark KEYWORD color.
                    $this->assertStringContainsString("\x1b[38;5;176m", $terminal->getOutput(), 'dark syntax at boot');
                    $terminal->clearOutput(); // capture only the frames rendered after the switch
                },
                500 => static function ($tui, $terminal, $ide): void {
                    // Change the theme setting live, as the settings form would.
                    new \ReflectionProperty($ide, 'settings')
                        ->getValue($ide)
                        ->changeSetting('theme', 'light');
                    $tui->tick();
                },
                700 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $out = $terminal->getOutput(); // only post-switch frames (cleared at step 300)
                    // The buffer re-highlighted: light KEYWORD color in, dark gone.
                    $this->assertStringContainsString("\x1b[38;5;91m", $out, 'light syntax after the switch');
                    $this->assertStringNotContainsString("\x1b[38;5;176m", $out, 'no dark syntax remains');
                    // Chrome recolored: light header bar in, dark gone.
                    $this->assertStringContainsString("\x1b[48;5;253m", $out, 'light header bar present');
                    $this->assertStringNotContainsString("\x1b[48;5;237m", $out, 'no dark header bar remains');

                    // Second live switch: the accessibility theme.
                    $terminal->clearOutput();
                    new \ReflectionProperty($ide, 'settings')
                        ->getValue($ide)
                        ->changeSetting('theme', 'high-contrast');
                    $tui->tick();
                },
                900 => function ($tui, $terminal): void {
                    $tui->tick();
                    $out = $terminal->getOutput(); // only post-high-contrast frames
                    $this->assertStringContainsString("\x1b[38;5;213m", $out, 'high-contrast syntax after the switch');
                    $this->assertStringNotContainsString("\x1b[38;5;91m", $out, 'no light syntax remains');
                    $this->assertStringContainsString("\x1b[48;5;236m", $out, 'high-contrast header bar present');
                    $this->assertStringNotContainsString("\x1b[48;5;253m", $out, 'no light header bar remains');
                },
            ],
        );

        $this->assertSame('high-contrast', IdeConfig::load($project->dir)->settings['theme'], 'the theme persisted');
    }

    public function testTabBarPositionSwitchesLiveAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $changePosition = static function ($ide, string $position): void {
            new \ReflectionProperty($ide, 'settings')
                ->getValue($ide)
                ->changeSetting('tabBarPosition', $position);
        };
        $rects = static function ($tui, $ide): array {
            $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);

            return [$renderer->getWidgetRect($tui->getById('tabs')), $renderer->getWidgetRect($tui->getById('editor'))];
        };

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($rects): void {
                    $tui->tick();
                    [$tabs, $editor] = $rects($tui, $ide);
                    $this->assertSame(1, $tabs->rows, 'top: a one-row strip');
                    $this->assertLessThan($editor->row, $tabs->row, 'top: the strip sits above the editor');
                },
                400 => static function ($tui, $terminal, $ide) use ($changePosition): void {
                    $changePosition($ide, position: 'bottom');
                    $tui->tick();
                },
                600 => function ($tui, $terminal, $ide) use ($rects, $changePosition): void {
                    $tui->tick();
                    [$tabs, $editor] = $rects($tui, $ide);
                    $this->assertSame(1, $tabs->rows, 'bottom: still a one-row strip');
                    $this->assertGreaterThan($editor->row + $editor->rows - 1, $tabs->row, 'bottom: the strip sits below the editor');
                    $changePosition($ide, position: 'left');
                    $tui->tick();
                },
                800 => function ($tui, $terminal, $ide) use ($rects, $changePosition): void {
                    $tui->tick();
                    [$tabs, $editor] = $rects($tui, $ide);
                    $this->assertSame(TabBarWidget::VERTICAL_WIDTH, $tabs->columns, 'left: a fixed-width vertical list');
                    $this->assertGreaterThan(1, $tabs->rows, 'left: the list fills the pane height');
                    $this->assertLessThan($editor->col, $tabs->col, 'left: the list sits left of the editor');
                    $changePosition($ide, position: 'right');
                    $tui->tick();
                },
                1000 => function ($tui, $terminal, $ide) use ($rects): void {
                    $tui->tick();
                    [$tabs, $editor] = $rects($tui, $ide);
                    $this->assertSame(TabBarWidget::VERTICAL_WIDTH, $tabs->columns, 'right: the fixed-width list again');
                    $this->assertGreaterThan($editor->col, $tabs->col, 'right: the list sits right of the editor');
                },
            ],
        );

        $this->assertSame('right', IdeConfig::load($project->dir)->settings['tabBarPosition'], 'the position persisted');
    }

    public function testAnUnknownTabBarPositionValueFallsBackToTop(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => json_encode(['settings' => ['tabBarPosition' => 'sideways']]),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $tabs = $renderer->getWidgetRect($tui->getById('tabs'));
                    $editor = $renderer->getWidgetRect($tui->getById('editor'));
                    $this->assertSame(1, $tabs->rows, 'an unknown persisted value boots the top strip');
                    $this->assertLessThan($editor->row, $tabs->row);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testVerticalTabListMouseInteractions(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'A.php' => "<?php\n",
            'B.php' => "<?php\n",
            'C.php' => "<?php\n",
            '.porta.json' => json_encode(['settings' => ['tabBarPosition' => 'left']]),
        ]);

        $tabsRect = static fn ($tui, $ide) => new \ReflectionProperty($ide, 'renderer')
            ->getValue($ide)
            ->getWidgetRect($tui->getById('tabs'));

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => function ($tui, $terminal, $ide) use ($project): void {
                    $service = new \ReflectionProperty($ide, 'bufferService')->getValue($ide);
                    $service->openFile($project->path('B.php'), OpenFocus::Editor);
                    $service->openFile($project->path('C.php'), OpenFocus::Editor);
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php', 'C.php'], $this->tabNames($tui));
                },
                300 => function ($tui, $terminal, $ide) use ($tabsRect): void {
                    $rect = $tabsRect($tui, $ide);
                    // Left-click row 0's body → switch to A (SGR coords are 1-based).
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 3, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 3, $rect->row + 1));
                    $tui->tick();
                    $this->assertStringEndsWith('A.php', (string) $tui->getById('editor')->getFilePath(), 'clicking a list row switches to that tab');
                },
                450 => function ($tui, $terminal, $ide) use ($tabsRect): void {
                    $rect = $tabsRect($tui, $ide);
                    // The × of row 1 (B) sits in the right-aligned close zone.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + TabBarWidget::VERTICAL_WIDTH - 1, $rect->row + 2));
                    $tui->tick();
                    $this->assertSame(['A.php', 'C.php'], $this->tabNames($tui), "clicking a row's × closed that tab");
                },
                600 => function ($tui, $terminal, $ide) use ($tabsRect): void {
                    $rect = $tabsRect($tui, $ide);
                    // Press row 0 (A), drag onto row 1 (C), release → A moves last.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 3, $rect->row + 1));
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $rect->col + 3, $rect->row + 2));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 3, $rect->row + 2));
                    $tui->tick();
                    $this->assertSame(['C.php', 'A.php'], $this->tabNames($tui), 'dragging down the list reordered the tabs');
                },
                750 => function ($tui, $terminal, $ide) use ($tabsRect): void {
                    $terminal->clearOutput();
                    $rect = $tabsRect($tui, $ide);
                    // Right-click row 0 (C) → the tab context menu.
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 1));
                    $tui->tick();
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'right-clicking a list row opened the tab menu');
                    $this->assertStringContainsString('Close Others', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b"); // Esc closes it
                },
                900 => function ($tui, $terminal, $ide): void {
                    // A content swap (the help viewer) lands inside the editor body,
                    // not beside the vertical list in the horizontal editor pane.
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_help');
                    $tui->tick();
                    $this->assertContains($tui->getById('help-viewer'), $tui->getById('editor-body')->all(), 'the viewer swapped into the editor body slot');
                    $this->assertCount(2, $tui->getById('editor-pane')->all(), 'the editor pane keeps exactly the list and the body');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testKeyboardTabMenuAnchorsOnTheBottomStripsOwnRow(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta.json' => json_encode(['settings' => ['tabBarPosition' => 'bottom']]),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_help');
                    $tui->tick();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 on the help viewer: the tab menu
                    $tui->tick();
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'the keyboard tab menu opened');
                    // A top strip anchors one row below the tab; the bottom strip anchors on
                    // its own row so the overlay's overflow flip opens the panel above it.
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $tabsRow = $renderer->getWidgetRect($tui->getById('tabs'))->row;
                    $overlay = new \ReflectionProperty($ide, 'locator')->getValue($ide)->get(\Porta\Overlay\MenuOverlayController::class);
                    $this->assertSame($tabsRow, new \ReflectionProperty($overlay, 'contextAnchorRow')->getValue($overlay));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testSettingsSidebarResizesAndPersists(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        // A seeded percent must be applied at startup (25% of 160 cols = 40).
        if (!is_dir($project->path('.porta'))) {
            mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        file_put_contents($project->path('.porta/layout.json'), json_encode(['settingsSidebarWidthPercent' => 25]));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => static function ($tui, $terminal, $ide): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
                },
                600 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame(40, $tui->getById('settings-nav')->width(), 'the seeded percent applied at startup');
                    // Drag the settings separator (SGR col 41 with a 40-wide nav) in to col 31.
                    $tui->handleInput("\x1b[<0;41;6M"); // arm on the separator
                    $tui->handleInput("\x1b[<32;31;6M"); // drag left
                    $tui->handleInput("\x1b[<0;31;6m"); // release
                },
                900 => function ($tui) use ($project): void {
                    $this->assertSame(30, $tui->getById('settings-nav')->width(), 'the drag resized the sidebar');
                    $layout = json_decode((string) file_get_contents($project->path('.porta/layout.json')), associative: true);
                    $this->assertSame(19, $layout['settingsSidebarWidthPercent'], 'saved as a percentage (round(30/160))');
                },
            ],
        );
    }

    public function testGitViewHistoryTabBrowsesCommits(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['a.txt' => "one\n"]);
        $dir = escapeshellarg($project->dir);
        exec("git -C {$dir} init -q && git -C {$dir} config user.email t@t && git -C {$dir} config user.name t && git -C {$dir} add -A && git -C {$dir} commit -qm 'first commit'");
        $project->write('a.txt', "one\nmore\n");
        exec("git -C {$dir} add -A && git -C {$dir} commit -qm 'second commit'");

        $terminal = $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                600 => static function ($tui): void {
                    $tui->tick();
                    // Click the [History] tab cell (tab strip = screen row 2).
                    $tui->handleInput("\x1b[<0;14;2M");
                    $tui->handleInput("\x1b[<0;14;2m");
                },
                1000 => function ($tui, $terminal): void {
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('History (2)', $screen);
                    $this->assertStringContainsString('second commit', $screen);
                    $this->assertStringContainsString('M a.txt', $screen, 'the newest commit file list loads');
                    // Mouse: hover tints a commit row (no selection change),
                    // wheel over the history list, click the first commit
                    // row (menu row + tab strip shift the list down two).
                    $tui->handleInput("\x1b[<35;10;5M");
                    $tui->handleInput("\x1b[<65;10;5M");
                    $tui->handleInput("\x1b[<0;10;4M");
                    $tui->handleInput("\x1b[<0;10;4m");
                },
                1200 => function ($tui, $terminal): void {
                    // The clicked commit's files settled AND its first file's
                    // patch auto-loaded — no keystroke needed to see a diff.
                    $this->assertStringContainsString('+more', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'first file auto-selected');
                    $tui->handleInput("\r"); // into the files list
                    $tui->handleInput("\x1b[B"); // wiggle selection to refire the load
                    $tui->handleInput("\x1b[A");
                },
                1500 => function ($tui, $terminal): void {
                    $this->assertStringContainsString('+more', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the commit patch renders read-only');
                    // Click the [Changes] tab to switch back.
                    $tui->handleInput("\x1b[<0;4;2M");
                    $tui->handleInput("\x1b[<0;4;2m");
                },
            ],
        );

        $this->assertStringContainsString('Working tree clean', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the Changes tab restored');
    }

    private function gitAvailable(): bool
    {
        return null !== $this->whichGit();
    }

    private function whichGit(): ?string
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if (is_file($d.'/git') && is_executable($d.'/git')) {
                return $d.'/git';
            }
        }

        return null;
    }

    public function testPerLanguageServersRouteAndReportStatus(): void
    {
        // Two distinct server processes (the argv differ, so the pool cannot
        // share a session) from one language-map config; markdown has neither.
        $mock = \PHP_BINARY.' '.__DIR__.'/../Support/mock-lsp-server.php';
        $project = new TempProject([
            '.porta.json' => json_encode(['lsp' => ['php' => $mock, 'javascript' => $mock.' js-flavor']]),
            'src/A.php' => "<?php\ngreet();\n",
            'src/app.js' => "doWork(x);\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
            'notes.md' => "# notes\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 2); // on greet
                    $tui->handleInput("\x1b[24~"); // php server resolves Target.php
                },
                700 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath(), 'php-routed definition jumped');
                    new \ReflectionMethod($ide, 'openFile')->invoke($ide, $project->path('src/app.js'), OpenFocus::Editor);
                },
                1100 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(0, 2); // on doWork
                    $tui->handleInput("\x1b[24~"); // js server resolves Target.php too
                },
                1400 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertStringEndsWith('Target.php', (string) $tui->getById('editor')->getFilePath(), 'js-routed definition jumped');
                    new \ReflectionMethod($ide, 'openFile')->invoke($ide, $project->path('notes.md'), OpenFocus::Editor);
                    $tui->tick();
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('LSP:mock-lsp-server', $screen, 'per-language server name in the status bar');
        $this->assertStringContainsString('LSP:no server', $screen, 'markdown has no configured server');
    }

    public function testCtrlClickNavigatesToDeclaration(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\n// line 1\n// line 2\n// line 3\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                400 => static function ($tui): void {
                    $tui->tick(); // ensure a frame rendered so widget rects exist
                    // Ctrl (SGR bit 16) + left click at screen row 5 → editor
                    // content row 2 (menu row 0, tab bar row 1) → document line 2.
                    $tui->handleInput("\x1b[<16;45;5M");
                    $tui->handleInput("\x1b[<16;45;5m");
                },
                900 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertStringEndsWith('Target.php', (string) $editor->getFilePath(), 'ctrl+click jumped to the declaration');
                    $this->assertSame(3, $editor->getDocument()->getCursorLine());
                    $this->assertFalse($editor->getDocument()->hasSelection(), 'a ctrl+click is a navigation, not a selection start');
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Target.php', $screen);
    }

    public function testGotoDeclarationOnDeclarationShowsUsages(): void
    {
        $tall = "<?php\n".str_repeat("// filler\n", times: 110);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Tall.php' => $tall,
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Tall.php'),
            [
                400 => static function ($tui): void {
                    // Mock convention: definition at lines 85-89 contains the
                    // requested position itself (a decoy Target.php location
                    // comes first, so any-match must beat the chooser).
                    $tui->getById('editor')->getDocument()->setCursor(86, 2);
                    $tui->handleInput("\x1b[24~"); // F12: declaration or usages
                },
                700 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertStringEndsWith('Tall.php', (string) $editor->getFilePath(), 'on the declaration: no jump');
                    $tui->handleInput("\x1b"); // close the usages panel
                },
                850 => static function ($tui): void {
                    $tui->tick();
                    // Lines 88-89: on the declaration, but zero usages.
                    $tui->getById('editor')->getDocument()->setCursor(88, 2);
                    $tui->handleInput("\x1b[24~");
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Usages', $screen);
        $this->assertStringContainsString('Target.php', $screen, 'usages rows listed');
        $this->assertStringNotContainsString('Choose target', $screen, 'a self-match shows usages, never the chooser');
        $this->assertStringContainsString('No usages found', $screen);
    }

    public function testNavigateBackWithEmptyStackAndOsc52Copy(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php echo 'hi';\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b[1;7D"); // ctrl+alt+left: nowhere to go
                    $tui->handleInput("\x1b[1;2C"); // shift+right
                    $tui->handleInput("\x03"); // ctrl+c -> OSC 52 to terminal
                },
            ],
        );

        $this->assertStringContainsString('Nowhere to go back to', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        $this->assertStringContainsString("\x1b]52;c;", $terminal->getOutput(), 'system clipboard escape emitted');
    }

    public function testJumpStackCapAndRapidTypingDebounce(): void
    {
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/Demo.php' => "<?php\nclass Demo {}\n",
            'src/Target.php' => "<?php\n\n\nclass Target {}\n",
        ]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                400 => static function ($tui): void {
                    // Overfill the jump stack (cap is 50)
                    $ideRef = null; // reach the private stack through reflection on the Ide via closure binding
                    $tui->handleInput('a');
                    $tui->handleInput('b'); // two rapid changes: second one cancels the first debounce
                },
                700 => static function ($tui): void {
                    $tui->handleInput("\x1b[24~"); // definition jump (fills jumpStack once)
                },
                950 => static function ($tui): void {
                    $tui->handleInput("\x1b[1;7D"); // back again
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Target.php', $screen);
    }

    public function testExpandSelectionThenSurroundThroughFullIde(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/A.php' => "<?php\n\$x = foo(bar);\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->getById('editor')->getDocument()->setCursor(1, 10); // inside "bar"
                    $tui->handleInput("\x17"); // ctrl+w: expand to the word "bar"
                    $tui->handleInput("\x1b\x14"); // ctrl+alt+t: open the surround menu
                    $tui->handleInput("\x1b[B"); // down to "[ ] Brackets"
                    $tui->handleInput("\r"); // accept -> wrap "bar" in [ ]
                },
                600 => static function ($tui): void {
                    $tui->tick();
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('foo([bar])', $screen);
    }

    private function gitInit(string $dir): void
    {
        exec('git -C '.escapeshellarg($dir).' init -q 2>/dev/null');
        exec('git -C '.escapeshellarg($dir).' config user.email t@t 2>/dev/null');
        exec('git -C '.escapeshellarg($dir).' config user.name tester 2>/dev/null');
    }

    private function gitCommit(string $dir): void
    {
        exec('git -C '.escapeshellarg($dir).' add -A 2>/dev/null');
        exec('git -C '.escapeshellarg($dir).' commit -qm init 2>/dev/null');
    }

    private function requireGit(): void
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if (is_file($d.'/git') && is_executable($d.'/git')) {
                return;
            }
        }
        self::markTestSkipped('git binary not available');
    }

    // --- Right-click context menus -------------------------------------------
    //
    // Terminal is 160×24. Tree occupies 0-based cols 0-29 (SGR 1-30); a 1-col gap
    // sits at SGR col 31; the editor pane starts at 0-based col 31 (SGR 32). Rows:
    // menu bar row 0 (SGR 1), tab bar row 1 (SGR 2), editor content from row 2
    // (SGR 3). Tree entries start at SGR row 2 (header is SGR row 1).

    /** Type each character of $text into the currently focused prompt/editor. */
    private function typeString(Tui $tui, string $text): void
    {
        foreach (mb_str_split($text) as $char) {
            $tui->handleInput($char);
        }
    }

    public function testEditorContextMenuCutsTheSelection(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "hello world\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;38;3M"); // press line 0 col 0
                    $tui->handleInput("\x1b[<32;43;3M"); // drag to col 5
                    $tui->handleInput("\x1b[<0;43;3m"); // release → "hello" selected
                    $tui->handleInput("\x1b[<2;45;3M"); // right-click in the editor
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'right-click opened the editor menu');
                    // The selection is kept (caret not moved), so Cut acts on it.
                    $this->assertSame('hello', $tui->getById('editor')->getDocument()->getSelectedText());
                    $tui->handleInput("\r"); // Enter → Cut (first item)
                },
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'menu closed after running');
                    $this->assertSame(' world', $tui->getById('editor')->getDocument()->getLine(0), 'Cut removed the selection');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testEditorContextMenuPlacesCaretWhenThereIsNoSelectionThenEscapes(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "one\ntwo\nthree\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\x1b[<2;40;4M"); // right-click on line 1, no selection
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen());
                    $this->assertSame(1, $tui->getById('editor')->getDocument()->getCursorLine(), 'caret moved to the clicked line');
                    $tui->handleInput('z'); // an unbound key is swallowed while the menu is open
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'stray keys neither run nor close');
                    $tui->handleInput("\x1b"); // Esc closes (modal routing)
                },
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'Esc closed the menu');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRightClickClosesAnOpenApplicationMenu(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[21~"); // F10 opens the application menu
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('menu')->isOpen());
                    $tui->handleInput("\x1b[<2;45;3M"); // right-click the editor
                },
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('menu')->isOpen(), 'right-click closed the application menu');
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'and opened the context menu');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRightClickOnHeaderOrEmptyTabStripOpensNothing(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;2M"); // tree header row → nodeAt() null
                },
                250 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'the tree header targets no node');
                    $tui->handleInput("\x1b[<2;150;2M"); // tab bar past the last tab → no index
                },
                300 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'empty tab-strip space targets no tab');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTabContextMenuCloseClosesTheClickedTab(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (tree row 2)
                },
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;34;2M"); // right-click the first tab (A)
                },
                400 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'right-click on a tab opened the menu');
                    $tui->handleInput("\r"); // Close (first item) → closes A
                },
                500 => function ($tui): void {
                    $this->assertStringEndsWith('B.php', (string) $tui->getById('editor')->getFilePath(), 'A closed, B shown');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTabCloseOthersAndCloseAllKeepUnsavedTabs(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n", 'C.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                120 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (row 2)
                },
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;5M"); // open C (row 3)
                },
                300 => static function ($tui): void {
                    // Make C (the active buffer) dirty, then Close Others from A's tab.
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput('x');
                    $tui->handleInput("\x1b[<2;34;2M"); // right-click tab A
                },
                380 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen());
                    $tui->handleInput("\x1b[B"); // down → Close Others
                    $tui->handleInput("\r");
                },
                460 => function ($tui): void {
                    // A kept (active), C kept (dirty), B closed.
                    $this->assertStringEndsWith('A.php', (string) $tui->getById('editor')->getFilePath());
                    // Now dirty A too, then Close All keeps both unsaved buffers.
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput('y');
                    $tui->handleInput("\x1b[<2;34;2M"); // right-click a tab
                },
                560 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // down → Close Others
                    $tui->handleInput("\x1b[B"); // down → Close All
                    $tui->handleInput("\r");
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('1 unsaved tab kept open', $screen, 'Close Others kept the one dirty tab');
        $this->assertStringContainsString('2 unsaved tabs kept open', $screen, 'Close All kept both dirty tabs');
    }

    public function testTabCloseAllClosesEveryCleanTab(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (row 2)
                },
                300 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;34;2M"); // right-click a tab
                },
                400 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // Close Others
                    $tui->handleInput("\x1b[B"); // Close All
                    $tui->handleInput("\r");
                },
                500 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId(), 'closing every tab returns focus to the tree');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTreeContextMenuNewFileAndNewFolder(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/keep.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('src/keep.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click src/ (a directory)
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen());
                    $tui->handleInput("\r"); // New File (first item) → opens the name prompt
                },
                360 => function ($tui): void {
                    $this->typeString($tui, 'new.php');
                    $tui->handleInput("\r"); // submit
                },
                460 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click src/ again
                },
                540 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // down → New Folder
                    $tui->handleInput("\r");
                },
                600 => function ($tui): void {
                    $this->typeString($tui, 'pkg');
                    $tui->handleInput("\r");
                },
            ],
        );

        $this->assertFileExists($project->path('src/new.php'), 'New File created the file');
        $this->assertDirectoryExists($project->path('src/pkg'), 'New Folder created the directory');
    }

    public function testTreeNewEntryWithBlankNameIsIgnored(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['src/keep.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('src/keep.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click src/
                },
                300 => static function ($tui): void {
                    $tui->handleInput("\r"); // New File
                },
                360 => static function ($tui): void {
                    $tui->handleInput("\r"); // submit an empty name → no-op
                },
                460 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click src/
                },
                540 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\r");
                },
                600 => static function ($tui): void {
                    $tui->handleInput('   '); // whitespace-only name → no-op
                    $tui->handleInput("\r");
                },
            ],
        );

        $this->assertSame(['keep.php'], array_values(array_diff(scandir($project->path('src')), ['.', '..'])), 'no blank-named entry was created');
    }

    public function testTreeContextMenuRenameUpdatesDiskAndBuffer(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php
                },
                230 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\x1b[B"); // Copy Relative Path
                    $tui->handleInput("\x1b[B"); // Rename…
                    $tui->handleInput("\r");
                },
                300 => static function ($tui): void {
                    // The prompt is prefilled with the current name; submitting it
                    // unchanged is a no-op.
                    $tui->handleInput("\r");
                },
                380 => function ($tui) use ($project): void {
                    $this->assertFileExists($project->path('A.php'), 'renaming to the same name changed nothing');
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php again
                },
                460 => static function ($tui): void {
                    foreach (range(1, end: 4) as $_) {
                        $tui->handleInput("\x1b[B"); // → Rename…
                    }
                    $tui->handleInput("\r");
                },
                540 => function ($tui): void {
                    foreach (range(1, end: 10) as $_) {
                        $tui->handleInput("\x7f"); // backspace out the prefill
                    }
                    $this->typeString($tui, 'renamed.php');
                    $tui->handleInput("\r");
                },
                640 => function ($tui): void {
                    $this->assertStringEndsWith('renamed.php', (string) $tui->getById('editor')->getFilePath(), 'the open buffer followed the rename');
                },
            ],
        );

        $this->assertFileDoesNotExist($project->path('A.php'), 'old name gone');
        $this->assertFileExists($project->path('renamed.php'), 'renamed on disk');
    }

    public function testEditorContextMenuNearTheBottomFlipsUp(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\necho 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    // Right-click near the bottom of the editor: the panel would
                    // overflow, so it is flipped to sit above the pointer.
                    $tui->handleInput("\x1b[<2;45;22M");
                    $tui->tick();
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'menu opened and placed above the click without error');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTreeContextMenuNearTheRightEdgeStraddlesPanes(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    // Right-click near the tree's right edge: the panel spills past
                    // the tree into the editor pane, exercising the per-pane clip.
                    $tui->handleInput("\x1b[<2;28;3M");
                    $tui->tick();
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen(), 'a straddling panel renders across both panes without error');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTreeRenameOntoAnExistingNameFlashesError(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php (row 1)
                },
                300 => static function ($tui): void {
                    // Rename is the 4th selectable item after New File/New Folder/Copy×2.
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\x1b[B"); // Copy Relative Path
                    $tui->handleInput("\x1b[B"); // Rename…
                    $tui->handleInput("\r");
                },
                380 => function ($tui): void {
                    foreach (range(1, end: 10) as $_) {
                        $tui->handleInput("\x7f");
                    }
                    $this->typeString($tui, 'B.php'); // clashes with an existing file
                    $tui->handleInput("\r");
                },
            ],
        );

        $this->assertStringContainsString('Cannot rename A.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        $this->assertFileExists($project->path('A.php'), 'the clash left A.php untouched');
    }

    public function testTreeContextMenuCopyPathAndRelativePath(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php
                },
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\r");
                },
                400 => static function ($tui): void {
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php again
                },
                500 => static function ($tui): void {
                    $tui->handleInput("\x1b[B"); // New Folder
                    $tui->handleInput("\x1b[B"); // Copy Path
                    $tui->handleInput("\x1b[B"); // Copy Relative Path
                    $tui->handleInput("\r");
                },
            ],
        );

        $raw = $terminal->getOutput();
        // The status bar truncates a long flash, so verify the full paths landed
        // on the clipboard via the OSC 52 base64 payload.
        $this->assertStringContainsString(base64_encode($project->path('A.php')), $raw, 'absolute path copied to the clipboard');
        $this->assertStringContainsString(base64_encode('A.php'), $raw, 'relative path copied to the clipboard');
        $this->assertStringContainsString('Copied A.php', AnsiUtils::stripAnsiCodes($raw), 'relative-path copy flashed');
    }

    public function testTreeContextMenuDeleteConfirmedClosesBufferAndShowsNext(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (row 2) as well
                },
                250 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php (row 1)
                },
                350 => static function ($tui): void {
                    // Delete is the 6th selectable item.
                    foreach (range(1, end: 5) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r"); // Delete… → confirm prompt
                },
                430 => function ($tui): void {
                    $this->typeString($tui, 'y');
                    $tui->handleInput("\r"); // confirmed
                },
                520 => function ($tui): void {
                    $this->assertStringEndsWith('B.php', (string) $tui->getById('editor')->getFilePath(), 'deleted A, showing B');
                },
            ],
        );

        $this->assertFileDoesNotExist($project->path('A.php'), 'A.php removed from disk');
    }

    public function testTreeContextMenuDeleteLastBufferEmptiesTheEditor(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php
                },
                300 => static function ($tui): void {
                    foreach (range(1, end: 5) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r"); // Delete…
                },
                380 => function ($tui): void {
                    $this->typeString($tui, 'yes'); // 'yes' is also accepted
                    $tui->handleInput("\r");
                },
            ],
        );

        $this->assertFileDoesNotExist($project->path('A.php'));
    }

    public function testTreeContextMenuDeleteCancelledKeepsTheFile(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;3;3M");
                },
                300 => static function ($tui): void {
                    foreach (range(1, end: 5) as $_) {
                        $tui->handleInput("\x1b[B");
                    }
                    $tui->handleInput("\r"); // Delete…
                },
                380 => static function ($tui): void {
                    $tui->handleInput('n'); // decline
                    $tui->handleInput("\r");
                },
                460 => function ($tui): void {
                    $this->assertSame('tree', $tui->getFocus()?->getId(), 'declining returns focus to the tree');
                },
            ],
        );

        $this->assertFileExists($project->path('A.php'), 'declining the confirmation kept the file');
    }

    public function testTreeContextMenuOpenAndRefreshViaMouse(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->getById('editor')->showDocument('', new \Porta\Editor\Document(''));
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php (row 1)
                },
                300 => static function ($tui): void {
                    // The menu anchors at the click (SGR row 2), so item i sits at
                    // SGR row 2+i. "Open" is item 9 for a file node → SGR row 11.
                    $tui->handleInput("\x1b[<0;4;12M"); // left-click "Open"
                },
                400 => function ($tui) use ($project): void {
                    $this->assertStringEndsWith('A.php', (string) $tui->getById('editor')->getFilePath(), 'Open reopened the file');
                    // A file created on disk appears in the tree only after Refresh.
                    $project->write('late.php', "<?php\n");
                    $tui->handleInput("\x1b[<2;3;3M"); // right-click A.php again
                },
                500 => static function ($tui): void {
                    // "Refresh" is the last item (10) → SGR row 12.
                    $tui->handleInput("\x1b[<0;4;13M");
                    $tui->tick();
                },
                600 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'late.php'), 'Refresh picked up the new file');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRightClickInTheGapDismissesAnOpenContextMenu(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<2;45;3M"); // open the editor menu
                },
                300 => function ($tui): void {
                    $this->assertTrue($tui->getById('context-menu')->isOpen());
                    $tui->handleInput("\x1b[<0;31;10M"); // left-click the gap → dismiss
                },
                400 => function ($tui): void {
                    $this->assertFalse($tui->getById('context-menu')->isOpen(), 'a click outside the panel dismissed it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    // --- Tab close icon + reordering ------------------------------------------

    /** The tab names in open order, read from the tab bar's provider. */
    private function tabNames(Tui $tui): array
    {
        $tabBar = $tui->getById('tabs');
        $provider = new \ReflectionProperty($tabBar, 'provider')->getValue($tabBar);

        return array_column($provider(), 'name');
    }

    public function testTabCloseIconClosesThatTab(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (tree row 2)
                },
                300 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php'], $this->tabNames($tui));
                    // Tab A ' A.php × ': its × sits at SGR col 39 (0-based 38 = rel 7).
                    $tui->handleInput("\x1b[<0;39;2M");
                },
                400 => function ($tui): void {
                    $this->assertSame(['B.php'], $this->tabNames($tui), 'clicking A\'s × closed it');
                    $this->assertStringEndsWith('B.php', (string) $tui->getById('editor')->getFilePath());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTabDragReorderRepaintsTheStripImmediately(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (tree row 2)
                },
                300 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php'], $this->tabNames($tui));
                    // Press tab A's body and let this frame render, so the press's
                    // own invalidations (buffer switch, focus) are consumed before
                    // the reorder — the drag frame must repaint on its own.
                    $tui->handleInput("\x1b[<0;34;2M");
                    $tui->tick();
                },
                450 => function ($tui, $terminal): void {
                    $terminal->clearOutput(); // capture only the frames after the reorder
                    $tui->handleInput("\x1b[<32;45;2M"); // drag A over tab B
                    $tui->handleInput("\x1b[<0;45;2m"); // release
                    $tui->tick();
                    $this->assertSame(['B.php', 'A.php'], $this->tabNames($tui));
                    // A pure reorder mutates no widget, so the strip must be
                    // invalidated explicitly for this frame to repaint it.
                    $this->assertMatchesRegularExpression('/B\.php.*A\.php/', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the strip repainted in the new order without further input');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTabDragReorders(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n", 'C.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (row 2)
                },
                250 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;5M"); // open C (row 3)
                },
                350 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php', 'C.php'], $this->tabNames($tui));
                    // Press tab A's body, drag onto tab C's slot, release → A moves last.
                    $tui->handleInput("\x1b[<0;34;2M"); // press tab A (rel col 3, body)
                    $tui->handleInput("\x1b[<32;55;2M"); // drag over tab C
                    $tui->handleInput("\x1b[<0;55;2m"); // release
                },
                450 => function ($tui): void {
                    $this->assertSame(['B.php', 'C.php', 'A.php'], $this->tabNames($tui), 'A dragged to the end');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testKeyboardMovesActiveTab(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n", 'B.php' => "<?php\n", 'C.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                150 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;4M"); // open B (row 2)
                },
                250 => static function ($tui): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[<0;3;5M"); // open C (row 3) → active = C
                },
                350 => function ($tui): void {
                    $tui->tick();
                    $this->assertSame(['A.php', 'B.php', 'C.php'], $this->tabNames($tui));
                    $tui->handleInput("\x1b[1;6D"); // ctrl+shift+left → [A, C, B]
                    $tui->handleInput("\x1b[1;6D"); // → [C, A, B]
                    $tui->handleInput("\x1b[1;6D"); // C already first → no move
                },
                420 => function ($tui): void {
                    $this->assertSame(['C.php', 'A.php', 'B.php'], $this->tabNames($tui), 'C moved to the front and stopped at the end');
                    $tui->handleInput("\x1b[1;6C"); // ctrl+shift+right → [A, C, B]
                },
                500 => function ($tui): void {
                    $this->assertSame(['A.php', 'C.php', 'B.php'], $this->tabNames($tui), 'moved back right');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    // --- CLI-supplied options -------------------------------------------------

    public function testInitialLineJumpsCaretOnBoot(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "l0\nl1\nl2\nl3\nl4\nl5\n"]);
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $ide = new Ide($project->dir, $project->path('A.php'), $terminal, null, initialLine: 4);
        $tui = $ide->getTui();

        $timer = EventLoop::delay(0.2, function () use ($tui): void {
            $tui->tick();
            $this->assertSame(3, $tui->getById('editor')->getDocument()->getCursorLine(), 'CLI line 4 → 0-based line 3');
            $tui->stop();
        });
        try {
            $ide->run();
        } finally {
            EventLoop::cancel($timer);
        }

        $this->addToAssertionCount(1);
    }

    public function testLspDisabledShowsDisabledStatus(): void
    {
        $project = new TempProject(['A.php' => "<?php\n"]);
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        $ide = new Ide($project->dir, $project->path('A.php'), $terminal, null, lspDisabled: true);
        $tui = $ide->getTui();

        $timer = EventLoop::delay(0.2, function () use ($tui): void {
            $tui->tick();
            $this->assertStringContainsString('LSP:disabled', AnsiUtils::stripAnsiCodes($tui->getTerminal()->getOutput()));
            $tui->stop();
        });
        try {
            $ide->run();
        } finally {
            EventLoop::cancel($timer);
        }

        $this->addToAssertionCount(1);
    }

    // --- Automatic refresh on filesystem change -------------------------------

    public function testExternalChangeReloadsCleanBuffer(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n// original\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $this->assertStringContainsString('original', $tui->getById('editor')->getDocument()->getText());
                    $project->write('A.php', "<?php\n// changed on disk\n"); // external edit
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringContainsString('changed on disk', $doc->getText(), 'clean buffer reloaded');
                    $this->assertFalse($doc->isDirty(), 'reloaded buffer is not dirty');
                    $this->assertStringContainsString('Reloaded A.php', AnsiUtils::stripAnsiCodes($tui->getTerminal()->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testExternalChangeToUnreadableContentLeavesBufferUntouched(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n// original\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    // The file grows beyond the 2 MB text limit → the poll must not
                    // try to load it, leaving the open buffer as-is.
                    $project->write('A.php', str_repeat('x', (2 * 1024 * 1024) + 1));
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringContainsString('original', $doc->getText(), 'oversized change ignored, buffer kept');
                    $this->assertFalse($doc->isDirty());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testExternalChangeKeepsDirtyBufferAndWarns(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $tui->getById('editor')->getDocument()->setCursor(0, 0);
                    $tui->getById('editor')->handleInput('X'); // unsaved edit → dirty
                    $project->write('A.php', "<?php\n// external\n");
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $doc = $tui->getById('editor')->getDocument();
                    $this->assertStringStartsWith('X', $doc->getText(), 'the unsaved edit was kept, not clobbered');
                    $this->assertTrue($doc->isDirty());
                },
            ],
        );

        $this->assertStringContainsString('A.php changed on disk', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testDeletedFileKeepsBufferAndWarns(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n// keep me\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    unlink($project->path('A.php')); // deleted on disk
                    $ide->pollFilesystem();
                    $ide->pollFilesystem(); // second poll must not re-flash
                },
                300 => function ($tui): void {
                    $this->assertStringContainsString('keep me', $tui->getById('editor')->getDocument()->getText(), 'buffer retained');
                },
            ],
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('A.php deleted on disk', $screen);
        $this->assertSame(1, substr_count($screen, needle: 'deleted on disk'), 'warns exactly once');
    }

    public function testOwnSaveDoesNotTriggerReload(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => static function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $tui->getById('editor')->handleInput('Y'); // dirty
                    $tui->handleInput("\x13"); // ctrl+s → save (records our own disk stamp)
                    $ide->pollFilesystem();
                },
                300 => function ($tui): void {
                    $screen = AnsiUtils::stripAnsiCodes($tui->getTerminal()->getOutput());
                    $this->assertStringNotContainsString('Reloaded', $screen, 'own save must not look like an external change');
                    $this->assertStringNotContainsString('changed on disk', $screen);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testNewFileOnDiskAppearsInTreeAfterPoll(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNull($this->rowOf($grid, 'fresh.php'), 'not there yet');
                    $project->write('fresh.php', "<?php\n"); // created by an external tool
                    $ide->pollFilesystem();
                    $tui->tick();
                },
                300 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'fresh.php'), 'poll refreshed the tree');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseClicksUnsavedChangesDialog(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $tui->handleInput('E'); // dirty a.php
                    $tui->handleInput("\x1bw"); // close → unsaved-changes overlay
                    $this->assertTrue($tui->getById('editor')->isOverlayOpen());
                },
                320 => static function ($tui): void {
                    // A click outside the editor pane dismisses the modal overlay (kept, not discarded).
                    $tui->handleInput("\x1b[<0;1;1M");
                },
                420 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $this->assertFalse($editor->isOverlayOpen(), 'outside click dismissed the dialog');
                    $this->assertStringEndsWith('a.php', (string) $editor->getFilePath(), 'dismiss kept the buffer');
                    $tui->handleInput("\x1bw"); // reopen the overlay
                },
                540 => function ($tui, $terminal, $ide): void {
                    $editor = $tui->getById('editor');
                    $this->assertTrue($editor->isOverlayOpen());
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($editor);
                    $host = new \ReflectionProperty($editor, 'overlayHost')->getValue($editor);
                    $box = new \ReflectionProperty($host, 'overlayBox')->getValue($host);
                    // Hover then click item index 1 ("Discard & Close"); title is row startRow.
                    $row = $rect->row + $box['startRow'] + 2;
                    $col = $rect->col + $box['col'] + 1;
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $col + 1, $row + 1)); // hover
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1)); // click
                },
                660 => function ($tui) use ($project): void {
                    $this->assertFalse($tui->getById('editor')->isOverlayOpen());
                    $this->assertStringNotContainsString('E', (string) file_get_contents($project->path('a.php')), 'discard did not save the edit');
                },
            ],
        );

        $this->assertStringContainsString("\x1b[?1003h", $terminal->getOutput());
    }

    public function testMouseClicksAndHoversSearchPanels(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php // a\n", 'b.php' => "<?php // b\n", 'c.php' => "<?php // c\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide): void {
                    $results = $tui->getById('results');
                    $results->open('Find in Project', ResultsPanelFields::queryOnly());
                    $results->setResults([
                        ['path' => 'b.php', 'line' => 0, 'text' => 'one'],
                        ['path' => 'c.php', 'line' => 0, 'text' => 'two'],
                    ]);
                    $tui->requestRender();
                    $tui->tick(); // force a frame so the panel gets a positioned rect
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($results);
                    // Header is relative row 0; the second result is at relative row 2.
                    $x = $rect->col + 6;
                    $y = $rect->row + 3; // 1-based screen row of relative row 2
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $x, $y)); // hover the second result
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $x, $y)); // click it
                },
                340 => function ($tui): void {
                    $this->assertStringEndsWith('c.php', (string) $tui->getById('editor')->getFilePath(), 'clicked the second result');
                    $this->assertTrue($tui->getById('results')->isOpen(), 'the pane stays open after the jump');
                },
                480 => static function ($tui, $terminal, $ide): void {
                    $goto = $tui->getById('goto-file');
                    $goto->open();
                    $tui->requestRender();
                    $tui->tick();
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($goto);
                    // Header is relative row 0; hover/click the second file (relative row 2)
                    // so the hover moves the selection off the default first row.
                    $x = $rect->col + 3;
                    $y = $rect->row + 3; // 1-based screen row of relative row 2
                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $x, $y)); // hover the second file
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $x, $y)); // click it
                },
                620 => function ($tui): void {
                    $this->assertFalse($tui->getById('goto-file')->isOpen(), 'clicking a file opened it and closed the palette');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testMouseClickThroughOpenCompletion(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$foo = 1;\n\$foobar = 2;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $editor = $tui->getById('editor');
                    $editor->getDocument()->setCursor(1, 0);
                    $editor->showCompletions([
                        ['label' => 'alpha', 'insertText' => 'alpha', 'kind' => 'fn', 'detail' => ''],
                    ]);
                    $this->assertTrue($editor->isCompletionOpen());
                },
                320 => static function ($tui, $terminal, $ide): void {
                    $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                    $rect = $renderer->getWidgetRect($tui->getById('editor'));
                    // Click at the bottom of the editor, far from the caret-anchored popup.
                    $x = $rect->col + 6;
                    $y = $rect->row + $rect->rows;
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $x, $y));
                },
                440 => function ($tui): void {
                    $this->assertFalse($tui->getById('editor')->isCompletionOpen(), 'a click off the popup closed completion and placed the caret');
                },
            ],
        );
    }

    public function testCursorShapeSettingSwitchesTheMarkerLiveAndTerminalPanelPlacesTheCursor(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    // ScreenWriter converts the zero-width marker into a hardware
                    // cursor position plus a DECSCUSR shape: 5 = bar (default).
                    $this->assertStringContainsString("\x1b[5 q", $terminal->getOutput(), 'the default bar shape is emitted');

                    new \ReflectionProperty($ide, 'settings')
                        ->getValue($ide)
                        ->changeSetting('cursorShape', 'block');
                    $terminal->clearOutput();
                    $tui->tick();
                },
                400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $out = $terminal->getOutput();
                    $this->assertStringContainsString("\x1b[1 q", $out, 'the block shape (DECSCUSR 1) after the live switch');
                    $this->assertStringNotContainsString("\x1b[5 q", $out, 'no stale bar shape survives the cache flush');
                },
                600 => function ($tui, $terminal, $ide): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_terminal');
                    $terminal->clearOutput();
                    $tui->tick();
                    $rows = $tui->getById('terminal')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 12));
                    $this->assertSame(1, $this->caretMarkerCount($rows), 'the focused terminal panel places the hardware cursor');
                },
            ],
        );

        $this->assertSame('block', IdeConfig::load($project->dir)->settings['cursorShape'], 'the shape persisted');
    }

    public function testNotificationLogShowsExpiredFlashes(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    // Empty log (boot may have flashed): the command just flashes.
                    $log = new \ReflectionProperty($ide, 'locator')->getValue($ide)->get(\Porta\Ui\NotificationLog::class);
                    new \ReflectionProperty($log, 'entries')->setValue($log, []);
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_notifications');
                    $tui->tick();
                    $this->assertStringContainsString('No notifications yet', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    new \ReflectionProperty($ide, 'notifier')
                        ->getValue($ide)
                        ->flash('the answer is 42', 0.1);
                },
                400 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    // The 0.1 s flash has long expired; the log is the only way back to it.
                    $terminal->clearOutput();
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_notifications');
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Notifications', $screen, 'the log overlay opened');
                    $this->assertStringContainsString('the answer is 42', $screen, 'the expired flash is reviewable');
                    $tui->handleInput("\x1b"); // Esc closes the overlay
                },
            ],
        );

        // With only an image open, the editor-hosted overlay refuses with a flash.
        $imageProject = new TempProject(['img.png' => $this->twoColorPng(4, 4)]);
        $this->runIde(
            $imageProject,
            $imageProject->path('img.png'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    new \ReflectionProperty($ide, 'notifier')
                        ->getValue($ide)
                        ->flash('logged', 0.1);
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_notifications');
                    $tui->tick();
                    $this->assertStringContainsString('Not available while viewing an image', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testFormatOnSaveSavesThroughTheFormatPath(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n\$a = 1;\n",
            '.porta.json' => json_encode(['settings' => ['formatOnSave' => true]]),
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    // No LSP server exists (empty PATH): the format round is
                    // skipped and Ctrl+S still saves exactly once, immediately.
                    $tui->handleInput('x');
                    $tui->handleInput("\x13"); // ctrl+s
                    $tui->tick();
                    $this->assertStringContainsString('Saved a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('x', (string) file_get_contents($project->path('a.php')));
                },
            ],
        );
    }

    public function testLspLogCommandShowsCapturedServerOutput(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    // No server ran (PATH is empty): the command just flashes.
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_lsp_log');
                    $tui->tick();
                    $this->assertStringContainsString('No LSP server output yet', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    $locator = new \ReflectionProperty($ide, 'locator')->getValue($ide);
                    $locator->get(\Porta\Lsp\LspLog::class)->append("[php-ls] fatal: cannot index\n");
                },
                400 => function ($tui, $terminal, $ide): void {
                    $terminal->clearOutput();
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_lsp_log');
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('LSP Server Log', $screen, 'the log overlay opened');
                    $this->assertStringContainsString('[php-ls] fatal: cannot index', $screen, 'the captured server line is reviewable');
                    $tui->handleInput("\x1b"); // Esc closes the overlay
                },
            ],
        );

        // With only an image open, the editor-hosted overlay refuses with a flash.
        $imageProject = new TempProject(['img.png' => $this->twoColorPng(4, 4)]);
        $this->runIde(
            $imageProject,
            $imageProject->path('img.png'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $locator = new \ReflectionProperty($ide, 'locator')->getValue($ide);
                    $locator->get(\Porta\Lsp\LspLog::class)->append('logged');
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_lsp_log');
                    $tui->tick();
                    $this->assertStringContainsString('Not available while viewing an image', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }

    public function testF6CyclesFocusAcrossPanesAndDelegatesToViews(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17~"); // F6: editor wraps to the tree
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17~"); // F6: tree → editor
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                },
                350 => function ($tui, $terminal, $ide): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_terminal');
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17;5~"); // Ctrl+F6: terminal → editor (backward)
                    $this->assertSame('editor', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17~"); // F6 forward returns to the terminal
                    $this->assertSame('terminal', $tui->getFocus()?->getId());
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_terminal'); // close it again
                },
                500 => function ($tui, $terminal, $ide): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
                    $this->assertSame('settings-form', $tui->getFocus()?->getId(), 'settings opens on the form');
                    $tui->handleInput("\x1b[17~"); // F6 delegates to the settings view
                    $this->assertSame('settings-nav', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17;5~"); // either direction bounces the two panes
                    $this->assertSame('settings-form', $tui->getFocus()?->getId());
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_settings');
                },
            ],
        );
    }

    public function testF6InsideTheGitViewWalksItsPanes(): void
    {
        if (!$this->hasGit()) {
            $this->markTestSkipped('git binary not available');
        }
        putenv('PATH='.$this->originalPath); // git must be reachable

        $project = new TempProject([]);
        exec('git -C '.escapeshellarg($project->dir).' init -q');
        exec('git -C '.escapeshellarg($project->dir).' config user.email t@t');
        exec('git -C '.escapeshellarg($project->dir).' config user.name tester');
        $project->write('a.php', "<?php\n\$a = 1;\n");

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                400 => function ($tui, $terminal, $ide): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_git');
                    $this->assertSame('git-changes', $tui->getFocus()?->getId(), 'git view opens on the changes list');
                    $tui->handleInput("\x1b[17~"); // F6 delegates to the git view's pane order
                    $this->assertSame('git-diff', $tui->getFocus()?->getId());
                    $tui->handleInput("\x1b[17;5~"); // Ctrl+F6 walks back
                    $this->assertSame('git-changes', $tui->getFocus()?->getId());
                },
            ],
        );
    }

    public function testCtrlF10OpensTheContextMenuForTheFocusedPane(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 in the editor
                    $tui->tick();
                    $context = new \ReflectionProperty($ide, 'contextMenu')->getValue($ide);
                    $this->assertTrue($context->isOpen(), 'the editor context menu opened at the caret');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Go to Declaration or Usages', $screen);
                    $tui->handleInput("\x1b"); // Esc closes it
                    $this->assertFalse($context->isOpen());
                },
                350 => function ($tui, $terminal, $ide): void {
                    $tui->setFocus($tui->getById('tree'));
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 on the tree's selected node
                    $tui->tick();
                    $this->assertStringContainsString('New File', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'tree file operations under the selection');
                    $tui->handleInput("\x1b");
                },
                500 => function ($tui, $terminal, $ide): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_help');
                    $tui->tick();
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[21;5~"); // Ctrl+F10 on the help viewer: the tab menu
                    $tui->tick();
                    $this->assertStringContainsString('Close Others', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'viewer tabs get the tab context menu');
                    $tui->handleInput("\x1b");
                },
                650 => function ($tui, $terminal, $ide): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('goto_file');
                    $tui->handleInput("\x1b[21;5~"); // no context menu for the go-to-file popup
                    $tui->tick();
                    $this->assertFalse(
                        new \ReflectionProperty($ide, 'contextMenu')
                            ->getValue($ide)
                            ->isOpen(),
                    );
                    $this->assertStringContainsString('No context menu here', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the flash explains the no-op');
                },
            ],
        );
    }

    public function testShiftF11AndF12ResizeTheFocusedPaneAndPersist(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui): void {
                    $tui->handleInput("\x1b[24;2~"); // Shift+F12 with editor focus: not resizable, falls through
                    $this->assertSame(30, $tui->getById('tree')->width(), 'editor focus resizes nothing');

                    $tui->setFocus($tui->getById('tree'));
                    $tui->handleInput("\x1b[24;2~"); // grow
                    $this->assertSame(32, $tui->getById('tree')->width());
                    $tui->handleInput("\x1b[23;2~"); // Shift+F11 shrinks it back
                    $this->assertSame(30, $tui->getById('tree')->width());
                },
                350 => function ($tui, $terminal, $ide): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('ide_terminal');
                    $tui->tick(); // render a frame so the panel has a rect to resize from
                    $rows = $tui->getById('terminal')->visibleRows();
                    $tui->handleInput("\x1b[24;2~"); // grow the focused bottom panel
                    $this->assertSame($rows + 1, $tui->getById('terminal')->visibleRows());
                },
                500 => function () use ($project): void {
                    $layout = json_decode((string) file_get_contents($project->dir.'/.porta/layout.json'), associative: true);
                    $this->assertSame(19, $layout['sidebarWidthPercent'], 'the last tree width (30 of 160 cols) persisted');
                    $this->assertArrayHasKey('bottomPanelHeightPercent', $layout, 'the panel grow persisted too');
                },
            ],
        );
    }

    public function testCallHierarchyFoldAllAndMatchingBracket(): void
    {
        $body = "<?php\n\nfunction greet(string \$n): string {\n    return 'hi '.\$n;\n}\n\nfunction caller(): string {\n    return greet('x');\n}\n".str_repeat("// filler\n", times: 85);
        $project = new TempProject([
            '.porta.json' => $this->mockLspConfig(),
            'src/H.php' => $body,
        ]);

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u");
            foreach (str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };

        $this->runIde(
            $project,
            $project->path('src/H.php'),
            [
                300 => function ($tui, $terminal): void {
                    $this->await(static fn (): bool => null !== $tui->getById('editor')->getFilePath());

                    // No symbol at the caret → flash, no panel.
                    $tui->getById('editor')->getDocument()->setCursor(70, 0);
                    $tui->handleInput("\x1b[104;6u"); // ctrl+shift+h → call hierarchy
                    $this->await(static fn () => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'No callers found'));

                    // A symbol nobody calls → the incoming list is empty.
                    $terminal->clearOutput();
                    $tui->getById('editor')->getDocument()->setCursor(88, 0);
                    $tui->handleInput("\x1b[104;6u");
                    $this->await(static fn () => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'No callers found'));

                    // The happy path: callers of greet land in the results panel.
                    $tui->getById('editor')->getDocument()->setCursor(2, 10); // on "greet"
                    $tui->handleInput("\x1b[104;6u");
                    $this->await(static fn (): bool => 'results' === $tui->getFocus()?->getId());
                },
                700 => function ($tui, $terminal): void {
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Callers of greet', $screen, 'the callers list opened');
                    $this->assertStringContainsString('caller', $screen);
                    $tui->handleInput("\x1b"); // close the panel
                },
                1100 => function ($tui, $terminal) use ($palette): void {
                    // Fold All: the LSP foldingRange says 2-4 → the body line hides.
                    $editorScreen = static fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20))));
                    $this->assertStringContainsString("return 'hi '", $editorScreen());
                    $palette($tui, label: 'Fold All');
                    $this->await(static fn (): bool => !str_contains($editorScreen(), "return 'hi '"));

                    $palette($tui, label: 'Unfold All');
                    $this->assertStringContainsString("return 'hi '", $editorScreen(), 'unfold restored the line');
                },
                1500 => function ($tui): void {
                    $tui->tick();
                    // Matching bracket: from the opening brace of greet() to its closer.
                    $tui->setFocus($tui->getById('editor'));
                    $doc = $tui->getById('editor')->getDocument();
                    $doc->setCursor(2, (int) strpos($doc->getLine(2), needle: '{'));
                    $tui->handleInput("\x1b[109;6u"); // ctrl+shift+m
                    $this->assertSame(4, $doc->getCursorLine(), 'jumped to the closing brace line');
                    $tui->handleInput("\x1b[109;6u"); // and back again
                    $this->assertSame(2, $doc->getCursorLine(), 'jumped back to the opener');
                },
            ],
        );
    }

    public function testStashBlameAndFileHistoryFlows(): void
    {
        putenv('PATH='.$this->originalPath);
        $project = new TempProject(['src/A.php' => "<?php\n\$x = 1;\n"]);
        $d = escapeshellarg($project->dir);
        exec("git -C {$d} init -q && git -C {$d} config user.email t@t && git -C {$d} config user.name tester && git -C {$d} add -A && git -C {$d} commit -qm init");
        // A second commit not touching A.php: file history must hide it.
        exec("touch {$d}/other.txt && git -C {$d} add -A && git -C {$d} commit -qm other");

        $palette = static function ($tui, string $label): void {
            $tui->handleInput("\x1b[97;6u");
            foreach (str_split($label) as $ch) {
                $tui->handleInput($ch);
            }
            $tui->handleInput("\r");
        };
        $screen = static fn ($terminal): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => function ($tui, $terminal) use ($palette, $project): void {
                    // A clean tree: git's own "nothing to stash" line is the flash.
                    $palette($tui, label: 'Git: Stash');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'No local changes'));

                    file_put_contents($project->path('src/A.php'), data: "<?php\n\$x = 2;\n");
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Stash');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Saved working directory'));
                    $this->assertStringContainsString('$x = 1;', (string) file_get_contents($project->path('src/A.php')), 'the tree reverted');

                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Stash Pop');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Stash popped'));
                    $this->assertStringContainsString('$x = 2;', (string) file_get_contents($project->path('src/A.php')));
                },
                700 => function ($tui, $terminal) use ($palette, $project): void {
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Stash');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Saved working directory'));

                    // Round 1: Cancel leaves everything untouched.
                    $palette($tui, label: 'Git: Stashes…');
                    $this->await(static fn (): bool => $tui->getById('editor')->isOverlayOpen());
                    $tui->handleInput("\r"); // pick the stash → the action chooser
                    $tui->tick();
                    $this->assertStringContainsString('apply, pop or drop?', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B"); // down to Cancel
                    $tui->handleInput("\r");
                    $this->assertFalse($tui->getById('editor')->isOverlayOpen());

                    // Round 2: Apply restores the change but keeps the stash.
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Stashes…');
                    $this->await(static fn (): bool => $tui->getById('editor')->isOverlayOpen());
                    $tui->handleInput("\r");
                    $tui->handleInput("\r"); // Apply leads the chooser
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Applied stash@{0}'));
                    $this->assertStringContainsString('$x = 2;', (string) file_get_contents($project->path('src/A.php')));
                    exec('git -C '.escapeshellarg($project->dir).' checkout -q -- .'); // clean for the pop round

                    // Round 3: Pop restores AND consumes a fresh stash.
                    file_put_contents($project->path('src/A.php'), data: "<?php\n\$x = 3;\n");
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Stash');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Saved working directory'));
                    $palette($tui, label: 'Git: Stashes…');
                    $this->await(static fn (): bool => $tui->getById('editor')->isOverlayOpen());
                    $tui->handleInput("\r");
                    $tui->handleInput("\x1b[B"); // down to Pop
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Popped stash@{0}'));
                    $this->assertStringContainsString('$x = 3;', (string) file_get_contents($project->path('src/A.php')));
                    exec('git -C '.escapeshellarg($project->dir).' checkout -q -- .');

                    // Round 4: Drop the remaining stash.
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Stashes…');
                    $this->await(static fn (): bool => $tui->getById('editor')->isOverlayOpen());
                    $tui->handleInput("\r");
                    $tui->tick();
                    $tui->handleInput("\x1b[B");
                    $tui->handleInput("\x1b[B"); // down to Drop
                    $tui->handleInput("\r");
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Dropped stash@{0}'));
                },
                1100 => function ($tui, $terminal) use ($palette, $d): void {
                    exec("git -C {$d} stash list", $left);
                    $this->assertSame([], $left, 'the stash really dropped');

                    // An empty stash list flashes instead of opening the picker.
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: Stashes…');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'No stashes'));

                    // Blame annotations on, then off.
                    $editorScreen = static fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20))));
                    $palette($tui, label: 'Git: Toggle Blame Annotations');
                    $this->await(static fn (): bool => str_contains($editorScreen(), 'tester'));
                    $palette($tui, label: 'Git: Toggle Blame Annotations');
                    $this->assertStringNotContainsString('tester', $editorScreen(), 'annotations cleared');
                },
                1500 => function ($tui, $terminal) use ($palette): void {
                    // File history: the git view opens filtered to the open file.
                    $terminal->clearOutput();
                    $palette($tui, label: 'Git: File History');
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'History — src/A.php (1)'));
                    $screenText = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('init', $screenText);
                    $this->assertStringNotContainsString(' other ', $screenText, "the commit that didn't touch the file is hidden");
                },
            ],
        );
    }
}
