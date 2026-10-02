<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A rebind and a reset on the keymap page reach the editor at once.
 *
 * The editor matches keys against the map it merged from the boot context,
 * so both used to land only on the next launch — and Del reset a binding in
 * the file while the old key went on firing.
 */
final class IdeLiveKeymapTest extends TestCase
{
    use ClearsPortaLsp;
    use NavigatesSettings;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    public function testARebindAndItsResetReachTheEditorWithoutARestart(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\none\n"]);
        $duplicates = static function (Ide $ide, string $key): string {
            $editor = Services::resolve($ide, CodeEditorWidget::class);
            $editor->getDocument()->replaceAll("one\n");
            $editor->getDocument()->setCursor(0, 0);
            $editor->handleInput($key);

            return $editor->getDocument()->getText();
        };

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->selectSettingsSection($this->settingsNav($tui), 'keybindings');
                    $list = $tui->getById('settings-keys');
                    $tui->setFocus($list);
                    $list->handleInput('/');
                    foreach (\str_split('duplicate line') as $character) {
                        $list->handleInput($character);
                    }
                    $list->handleInput("\r"); // arm capture on Duplicate Line
                    $list->handleInput("\x1bOS"); // F4
                    $this->assertSame(['f4'], IdeConfig::load($project->dir)->keymap['duplicate_line'] ?? null);
                },
                500 => function (Tui $tui, $terminal, Ide $ide) use ($duplicates, $project): void {
                    $this->assertSame("one\none\n", $duplicates($ide, key: "\x1bOS"), 'F4 duplicates now, not after a restart');
                    $this->assertSame("one\n", $duplicates($ide, key: "\x04"), 'and Ctrl+D no longer does');

                    $list = $tui->getById('settings-keys');
                    $list->handleInput("\x1b[3~"); // Del, on the row the filter shows
                    $this->assertArrayNotHasKey('duplicate_line', IdeConfig::load($project->dir)->keymap);
                    $this->assertSame("one\none\n", $duplicates($ide, key: "\x04"), 'the default is back at once');
                    $this->assertSame("one\n", $duplicates($ide, key: "\x1bOS"));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * On a Mac the menus name Ctrl until a Cmd chord has actually reached the
     * IDE — Terminal.app sends none, and a Cmd hint there would be a lie —
     * and from then on name that chord, and only that one.
     */
    #[RequiresMethod(Key::class, 'super')]
    public function testTheMenusNameACmdChordOnceItArrives(): void
    {
        \putenv('PATH=/nonexistent');
        $pinned = (string) \getenv('PORTA_KEYMAP_PLATFORM');
        \putenv('PORTA_KEYMAP_PLATFORM=macos');
        $project = new TempProject(['A.php' => "<?php\n"]);
        $editMenu = function (Tui $tui, VirtualTerminal $terminal): string {
            $tui->getById('menu')->open(1);
            $this->frame($tui);
            $screen = \implode("\n", $this->screenGrid($tui, $terminal));
            $tui->getById('menu')->close();

            return $screen;
        };

        try {
            $this->runIde(
                $project,
                $project->path('A.php'),
                [
                    300 => function (Tui $tui, VirtualTerminal $terminal) use ($editMenu): void {
                        $screen = $editMenu($tui, $terminal);
                        $this->assertStringContainsString('Ctrl+Z', $screen);
                        $this->assertStringNotContainsString('Cmd+', $screen, 'no Cmd chord has arrived yet');

                        $tui->handleInput("\x1b[122::119;9u"); // Cmd+Z, as Ghostty sends it on AZERTY
                        $screen = $editMenu($tui, $terminal);
                        $this->assertStringContainsString('Cmd+Z', $screen, 'the chord that arrived');
                        $this->assertStringContainsString('Ctrl+X', $screen, 'and not one that has not');
                    },
                ],
            );
        } finally {
            \putenv('PORTA_KEYMAP_PLATFORM='.$pinned);
        }

        $this->addToAssertionCount(1);
    }
}
