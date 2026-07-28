<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\I18n\Translator;
use Porta\Settings\SettingsKeymapController;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsKeymapControllerTest extends TestCase
{
    use StatusReads;

    private function controller(string $rootDir, StatusBarWidget $status, Keybindings $ideKeys): SettingsKeymapController
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));

        return new SettingsKeymapController(new CodeEditorWidget(), $ideKeys, new Notifier($status, $tui), $rootDir, new Translator('en'));
    }

    public function testRowsResolveEditorFirstThenIdeBindings(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new StatusBarWidget(), new Keybindings(['ide_problems' => ['ctrl+6']]));

        $rows = $controller->rows();
        $byAction = array_column($rows, null, 'action');

        $this->assertSame(\count(\Porta\Config\ActionCatalog::ACTIONS), \count($rows), 'one row per catalog action');
        $this->assertSame('ctrl+d', $byAction['duplicate_line']->binding, 'editor binding wins');
        $this->assertSame('Duplicate Line or Selection', $byAction['duplicate_line']->label);
        $this->assertSame('ctrl+6', $byAction['ide_problems']->binding, 'ide binding fills in when the editor has none');
    }

    public function testRebindPersistsTheParsedTokenAndFlashesRestart(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());

        $controller->rebind('duplicate_line', "\x1b[24~"); // F12

        $this->assertSame(['f12'], IdeConfig::load($project->dir)->keymap['duplicate_line']);
        $flash = $this->flashText($status);
        $this->assertStringContainsString('Rebound Duplicate Line or Selection to F12', $flash);
        $this->assertStringContainsString('applies on restart', $flash);
    }

    public function testRebindWithAModifierChordPersistsTheFullToken(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new StatusBarWidget(), new Keybindings());

        $controller->rebind('save', "\x04"); // ctrl+d
        $this->assertSame(['ctrl+d'], IdeConfig::load($project->dir)->keymap['save']);
    }

    public function testAnUnparseableKeyFlashesAndPersistsNothing(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());

        $controller->rebind('save', "\x1b[17;2~"); // legacy shift+F6, not decodable here

        $this->assertStringContainsString('Unsupported key', $this->flashText($status));
        $this->assertFalse(is_file($project->path('.porta.json')));
    }

    public function testAFailedPersistIsFlagged(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json')); // block the write

        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), new Keybindings());
        $controller->rebind('save', 'a');

        $this->assertStringContainsString('(not saved)', $this->flashText($status));
    }
}
