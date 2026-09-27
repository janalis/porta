<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\ReadsSettingsForm;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The four settings-form behaviours that were only ever checked by hand.
 *
 * Each was fixed with a unit test on the widget or the controller, which is
 * where the logic lives — but each is also the kind of thing that survives its
 * unit test and breaks in the real layout, where the pane has the height the
 * workbench gives it and the rows are the rows the real controllers produce.
 * A checklist someone has to remember is not a gate; this is.
 */
final class SettingsFormBehaviourTest extends TestCase
{
    use ClearsPortaLsp;
    use NavigatesSettings;
    use PreservesPath;
    use ReadsSettingsForm;
    use RunsIde;
    use ScreenReads;

    /**
     * A page taller than its pane can be walked to the end.
     *
     * The form used to render from index 0 with no scroll offset, so the rows
     * past the fold were unreachable and the selection walked off-screen
     * invisibly. Twelve terminal rows leaves the content pane shorter than the
     * Interface page is long — at sixteen the whole page still fits, which is
     * worth knowing before someone "tidies" this number upward.
     */
    public function testTheKeybindingsPageCanBeFiltered(): void
    {
        // 187 actions, and finding one used to mean scrolling past every caret
        // movement in the editor — the E2e that rebinds `save_all` walks up to
        // 200 rows to reach it.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->selectSettingsSection($this->settingsNav($tui), 'keybindings');
                    $this->frame($tui);
                },
                500 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $list = $tui->getById('settings-keys');
                    $tui->setFocus($list);
                    $list->handleInput('/');
                    foreach (\str_split('duplicate') as $character) {
                        $list->handleInput($character);
                    }
                    $this->frame($tui);

                    $screen = \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->assertStringContainsString('┃ duplicate ', $screen);
                    $this->assertStringContainsString('Duplicate Line', $screen, 'the action it was typed for');
                    $this->assertStringNotContainsString('Move Caret Up', $screen, 'and not the 186 it was not');
                    $this->assertMatchesRegularExpression('/Keybindings — \d+ of \d+/', $screen, 'the header counts what is left');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAPageTallerThanThePaneScrollsToItsLastRow(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $this->settingsNav($tui);
                    $this->selectSettingsSection($nav, 'interface');
                    $this->frame($tui);
                },
                500 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $form = $this->settingsForm($tui);
                    $tui->setFocus($form);

                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Language'), 'the page opens showing its early rows');
                    $this->assertNull($this->rowOf($grid, 'Diagnostic messages'), 'and its last row is below the fold');

                    // Walk to the end with real input, as a user would.
                    for ($i = 0; $i < self::MAX_FORM_ROWS; ++$i) {
                        $tui->handleInput("\x1b[B");
                    }
                    $this->frame($tui);

                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Diagnostic messages'), 'the last row scrolled into view');
                    $this->assertStringContainsString(
                        'Diagnostic messages',
                        $this->selectedSettingRow($form),
                        'and the selection is ON it, not off the bottom of the pane',
                    );
                },
            ],
            rows: 12,
        );
    }

    /**
     * A click on a row that is not selected selects it and nothing else.
     *
     * `handleClick()` used to be select-then-actuate, so one stray click
     * toggled whatever it landed on — including arming a plugin removal. A
     * checkbox is the one exception (it toggles on its first click, as a
     * `<label>` does), so the click lands on a select's label.
     */
    public function testAStrayClickSelectsWithoutChangingAnything(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->frame($tui);
                },
                500 => function (Tui $tui, VirtualTerminal $terminal) use ($project): void {
                    $grid = $this->screenGrid($tui, $terminal);
                    $row = $this->rowOf($grid, 'Save changes to');
                    $this->assertNotNull($row, 'the General page shows the row we are about to click');

                    // One press+release on it. SGR coordinates are 1-based.
                    $tui->handleInput(\sprintf("\x1b[<0;40;%dM", $row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;40;%dm", $row + 1));
                    $this->frame($tui);

                    $form = $this->settingsForm($tui);
                    $this->assertStringContainsString(
                        'Save changes to',
                        $this->selectedSettingRow($form),
                        'the click moved the selection',
                    );
                    $this->assertFileDoesNotExist(
                        $project->path('.porta.json'),
                        'and wrote nothing — a click that mutates is the defect this replaced',
                    );
                    $this->assertTrue(
                        IdeConfig::load($project->dir)->settings['autoPairs'],
                        'the setting still holds its default in memory too',
                    );
                },
            ],
        );
    }

    /**
     * A value the section refuses keeps the editor open with the text as typed.
     *
     * Re-pushing the rows on refusal is what used to snap the row back to the
     * persisted value and destroy the typing — which is the worst possible
     * response to "you got this slightly wrong".
     */
    public function testARefusedLaunchConfigKeepsWhatYouTyped(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $this->settingsNav($tui);
                    $this->selectSettingsSection($nav, 'lang:php');
                    $this->frame($tui);
                },
                500 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $form = $this->settingsForm($tui);
                    $tui->setFocus($form);
                    $this->selectSettingRow($form, 'Launch config');

                    $tui->handleInput("\r"); // open the inline editor
                    foreach (\str_split('{not json') as $char) {
                        $tui->handleInput($char);
                    }
                    $tui->handleInput("\r"); // commit → the section refuses
                    $this->frame($tui);

                    $this->assertNotNull(
                        $this->rowOf($this->screenGrid($tui, $terminal), '{not json'),
                        'the text survived the refusal instead of snapping back',
                    );
                },
            ],
        );
    }

    /**
     * The modified marker tracks the VALUE, not the presence of a key.
     *
     * A fresh project sets nothing, so nothing is marked; changing a setting
     * marks that row and only that row.
     */
    public function testTheModifiedMarkerAppearsOnlyOnWhatYouChanged(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "<?php\n\$x = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $nav = $this->settingsNav($tui);
                    $this->selectSettingsSection($nav, 'interface');
                    $this->frame($tui);
                },
                500 => function (Tui $tui, $terminal): void {
                    $form = $this->settingsForm($tui);
                    $tui->setFocus($form);

                    // The FORM's own render, not the screen: a toast saying
                    // "Sticky scroll off" also carries the label, and the
                    // label-addressed helper would happily read that instead.
                    // Tall enough for the whole page: its boxed controls are
                    // three rows each.
                    $rows = Paint::lines($form, new RenderContext(100, 40));
                    $this->assertStringNotContainsString('●', $this->settingRow($rows, 'Sticky scroll'), 'a fresh project has changed nothing');

                    $this->selectSettingRow($form, 'Sticky scroll');
                    $tui->handleInput("\r"); // toggle it off its default
                    $this->frame($tui);

                    $rows = Paint::lines($form, new RenderContext(100, 40));
                    $this->assertStringContainsString('●', $this->settingRow($rows, 'Sticky scroll'), 'the changed row is marked');
                    $this->assertStringNotContainsString('●', $this->settingRow($rows, 'Indent guides'), 'and its neighbour is not');
                },
            ],
        );
    }
}
