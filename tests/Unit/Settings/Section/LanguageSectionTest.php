<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings\Section;

use PHPUnit\Framework\TestCase;
use Porta\Config\PortaJsonWriter;
use Porta\Debug\DebugAdapters;
use Porta\I18n\Translator;
use Porta\Lsp\BufferReopener;
use Porta\Lsp\LanguageServerProvider;
use Porta\Lsp\LspReconfigurer;
use Porta\Lsp\LspSessionPool;
use Porta\Settings\Section\LanguageSection;
use Porta\Settings\SettingsDebugController;
use Porta\Settings\SettingsLanguageController;
use Porta\Settings\SettingsLspController;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class LanguageSectionTest extends TestCase
{
    use StatusReads;

    private function section(string $rootDir, StatusBarWidget $status): LanguageSection
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $notifier = new Notifier($status, $tui);
        $languages = new SettingsLanguageController(new CodeEditorWidget(), $notifier, $rootDir, new Translator('en'), [], new PortaJsonWriter());
        $pool = new LspSessionPool($rootDir, new FixedActiveFile(), new Translator('en'));
        $reconfigurer = new LspReconfigurer(
            $pool,
            new class implements BufferReopener {
                public function reopenAllInLsp(): void {}
            },
            new class implements LanguageServerProvider {
                public function languageServers(): array
                {
                    return [];
                }
            },
            $rootDir,
            null,
        );
        $lsp = new SettingsLspController($rootDir, [], $reconfigurer, $notifier, new Translator('en'), new PortaJsonWriter());
        $debug = new SettingsDebugController($rootDir, new DebugAdapters([]), $notifier, new Translator('en'), new PortaJsonWriter());

        return new LanguageSection($languages, $lsp, $debug);
    }

    public function testRowsConcatenateAllThreeControllersInFull(): void
    {
        // languages->rows() returns 3 rows (indentStyle/indentSize/runCommand),
        // lsp->rows() returns exactly 1, debug->rows() returns 2 — spreading
        // "only the first" of any of them would silently drop rows.
        $tempProject = new TempProject();
        $rows = $this->section($tempProject->dir, new StatusBarWidget())->rows('lang:php');

        $keys = array_column($rows, 'key');
        $this->assertSame(['indentStyle', 'indentSize', 'runCommand', 'lspServer', 'debugAdapter', 'debugLaunch'], $keys);
    }

    public function testChangeLspServerRoutesOnlyToLspNotLanguages(): void
    {
        $project = new TempProject();
        $status = new StatusBarWidget();
        $this->section($project->dir, $status)->change('lang:php', 'lspServer', 'phpactor');

        // A fallthrough into changeLanguageSetting() would reject 'lspServer'
        // as an unknown key and overwrite this flash with its own.
        $this->assertStringContainsString('PHP LSP: phpactor', $this->flashText($status));
    }

    public function testChangeDebugAdapterRoutesOnlyToDebugNotLanguages(): void
    {
        $project = new TempProject();
        $status = new StatusBarWidget();
        $this->section($project->dir, $status)->change('lang:php', 'debugAdapter', 'php-debug-adapter');

        // Same fallthrough risk as the lspServer case, on the other guard.
        $this->assertStringNotContainsString('Unknown language setting', $this->flashText($status));
    }
}
