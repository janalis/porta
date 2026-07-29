<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The inline image viewer: half-block rendering, zoom/pan, tab
 * safety and rejection of oversized or corrupt files.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeImageViewerTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

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
                    // Go-to-file is now the editor-hosted picker (a files-only
                    // Search Everywhere), so with only an image tab it refuses
                    // with the same flash the other editor modals give.
                    $tui->handleInput("\x10"); // ctrl+p
                    $tui->tick();
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId(), 'go-to-file refuses with only an image tab');
                    $this->assertStringContainsString('Not available in this view', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
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
                    $service = Services::resolve($ide, BufferService::class);
                    $service->openFile($project->path('img.png'), OpenFocus::Editor);
                    $tui->tick();
                    $editor = Services::resolve($ide, CodeEditorWidget::class);
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
                    $service = Services::resolve($ide, BufferService::class);
                    $service->pollFilesystem();
                    $buffers = Services::resolve($ide, BufferManager::class);
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
                    $this->assertStringContainsString('Not available in this view', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        // With a text tab open too, the modal hops to it first.
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)
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
                    $service = Services::resolve($ide, BufferService::class);
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
}
