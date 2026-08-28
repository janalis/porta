<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Settings\SettingsSearchLauncher;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\ReadsSettingsForm;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\Settings\SettingsFormWidget;

/**
 * "Find Setting", end to end: Ctrl+F inside the settings view opens the
 * centered picker, and picking a hit lands on that setting's row.
 *
 * Driven through real keystrokes rather than the launcher, because the whole
 * risk of this feature is in the hops between them — the widget's keymap, the
 * command event, the overlay that has to paint over a full-screen view, and the
 * open-then-dispatch order that decides whether the row survives the page
 * switch.
 */
final class SettingsSearchTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use ReadsSettingsForm;
    use RunsIde;
    use ScreenReads;

    public function testCtrlFFindsASettingOnAnotherPageAndLandsOnItsRow(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                },
                500 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Inline diagnostics'), 'the view opens on General');
                    $this->assertNull($this->rowOf($grid, 'Sticky scroll'), 'and Interface rows are elsewhere');

                    $tui->handleInput("\x06"); // ctrl+f
                },
                700 => function ($tui, $terminal): void {
                    $this->assertNotNull(
                        $this->rowOf($this->screenGrid($tui, $terminal), 'Find Setting'),
                        'the picker paints over the full-screen settings view',
                    );
                    foreach (str_split('sticky') as $char) {
                        $tui->handleInput($char);
                    }
                },
                900 => function ($tui, $terminal): void {
                    $this->assertNotNull(
                        $this->rowOf($this->screenGrid($tui, $terminal), 'Sticky scroll'),
                        'the hit is listed while its page is not the one showing',
                    );
                    $tui->handleInput("\r");
                },
                1100 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNull($this->rowOf($grid, 'Find Setting'), 'the picker closed');
                    $this->assertNotNull($this->rowOf($grid, 'Sticky scroll'), 'the Interface page is showing');

                    $form = $tui->getById('settings-form');
                    \assert($form instanceof SettingsFormWidget);
                    $this->assertStringContainsString(
                        'Sticky scroll',
                        $this->selectedSettingRow($form),
                        'and the selection is on the picked row, not merely on its page',
                    );
                },
            ],
        );
    }

    /**
     * The same picker with the settings view CLOSED, which is the case that
     * makes the launcher's open-then-dispatch order matter:
     * `SettingsViewController::open()` resets to the General page, so a view
     * opened after the event would throw away the section the event carried
     * and land on General every time.
     *
     * Reached through the launcher rather than a keystroke because Ctrl+F is a
     * settings-widget binding and there is no widget to press it on here.
     */
    public function testPickingWithTheViewClosedOpensItOnTheSettingsOwnPage(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, SettingsSearchLauncher::class)->open();
                },
                500 => function ($tui, $terminal): void {
                    $this->assertNotNull(
                        $this->rowOf($this->screenGrid($tui, $terminal), 'Find Setting'),
                        'the picker opens over the editor',
                    );
                    foreach (str_split('sticky') as $char) {
                        $tui->handleInput($char);
                    }
                },
                700 => static function ($tui): void {
                    $tui->handleInput("\r");
                },
                900 => function ($tui, $terminal): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Sticky scroll'), 'the settings view opened on Interface');
                    $this->assertNull($this->rowOf($grid, 'Inline diagnostics'), 'NOT on General, which is where open() resets to');

                    $form = $tui->getById('settings-form');
                    \assert($form instanceof SettingsFormWidget);
                    $this->assertStringContainsString('Sticky scroll', $this->selectedSettingRow($form), 'landed on the row');
                },
            ],
        );
    }
}
