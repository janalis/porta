<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings\Section;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
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
use Porta\Settings\SettingsProvenance;
use Porta\Settings\SettingsScope;
use Porta\Settings\SettingsScopeRow;
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
        $files = ConfigFiles::for($rootDir);
        // Pinned to Project so the persists below land in the temp project;
        // the scope defaults to Global, i.e. the suite's fixture home.
        $scope = new SettingsScope($files);
        $scope->set(ConfigScope::Project);
        $languages = new SettingsLanguageController(
            new CodeEditorWidget(),
            $notifier,
            new Translator('en'),
            [],
            new PortaJsonWriter(ConfigFiles::for($rootDir)),
            $scope,
            new SettingsProvenance($files),
        );
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

                public function missingServers(): array
                {
                    return [];
                }
            },
            $rootDir,
            null,
        );
        $lsp = new SettingsLspController(
            $rootDir,
            [],
            $reconfigurer,
            $notifier,
            new Translator('en'),
            new PortaJsonWriter(ConfigFiles::for($rootDir)),
            $scope,
            new SettingsProvenance(ConfigFiles::for($rootDir)),
        );
        $debug = new SettingsDebugController(
            new DebugAdapters([]),
            $notifier,
            new Translator('en'),
            new PortaJsonWriter(ConfigFiles::for($rootDir)),
            $scope,
            new SettingsProvenance(ConfigFiles::for($rootDir)),
        );

        return new LanguageSection($languages, $lsp, $debug, new SettingsScopeRow($scope, $files, new Translator('en')));
    }

    /**
     * The scope chooser leads the page and is routed by the section, not by
     * any of the three controllers behind it — none of which has heard of it.
     */
    public function testTheScopeRowLeadsThePageAndIsRoutedBySection(): void
    {
        $project = new TempProject();
        $section = $this->section($project->dir, new StatusBarWidget());

        $rows = $section->rows('lang:php');
        $this->assertSame(SettingsScopeRow::KEY, $rows[0]->key, 'the chooser leads');
        $this->assertSame('project', $rows[0]->value, 'showing the pinned scope');

        $this->assertTrue($section->change('lang:php', SettingsScopeRow::KEY, 'global'));

        $this->assertSame('global', $section->rows('lang:php')[0]->value, 'and the choice took');
    }

    public function testRowsConcatenateAllThreeControllersInFull(): void
    {
        // languages->indentRows() returns 2 rows, lsp->rows() exactly 1,
        // languages->runRows() 1 and debug->rows() 2 — spreading "only the
        // first" of any of them would silently drop rows. The order keeps each
        // fieldset contiguous: Indentation, Language server, Run & debug.
        $tempProject = new TempProject();
        $rows = $this->section($tempProject->dir, new StatusBarWidget())->rows('lang:php');

        // SettingsScopeRow::lead() prepends the shared "Save changes to" row to
        // every page that writes a file, so index past it: what this test is
        // about is the three controllers' own rows, not the page's lead.
        $this->assertSame(SettingsScopeRow::KEY, $rows[0]->key);
        $keys = \array_column(\array_slice($rows, offset: 1), 'key');
        $this->assertSame(['indentStyle', 'indentSize', 'lspServer', 'runCommand', 'debugAdapter', 'debugLaunch'], $keys);
        $this->assertSame(['Indentation', 'Indentation', 'Language server', 'Run & debug', 'Run & debug', 'Run & debug'], \array_column(\array_slice($rows, offset: 1), 'fieldset'));
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
