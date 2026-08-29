<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Settings\SettingsScope;
use Porta\Settings\SettingsScopeRow;
use Porta\Tests\Support\TempProject;

final class SettingsScopeRowTest extends TestCase
{
    /** Held so the destructor does not remove the dir mid-test. */
    private TempProject $project;

    private TempProject $home;

    /** The ordinary case: a home exists, so both scopes are offerable. */
    private function row(): SettingsScopeRow
    {
        $this->home = new TempProject([]);

        return $this->rowFor($this->home->dir);
    }

    /** No home: one writable file, so there is nothing to choose between. */
    private function rowWithoutAHome(): SettingsScopeRow
    {
        return $this->rowFor(null);
    }

    private function rowFor(?string $home): SettingsScopeRow
    {
        $this->project = new TempProject([]);
        $files = new ConfigFiles($this->project->dir, $home);

        return new SettingsScopeRow(new SettingsScope($files), $files, new Translator('en'));
    }

    public function testTheRowOffersBothFilesByName(): void
    {
        $row = $this->row()->row();

        $this->assertNotNull($row);
        $this->assertSame(SettingsRowKind::Choice, $row->kind);
        $this->assertSame(['global', 'project'], $row->options);
        $this->assertSame('~/.porta/config.json', $row->optionLabels['global'] ?? null);
        $this->assertSame('.porta.json', $row->optionLabels['project'] ?? null);
    }

    /**
     * A page where the scope decides more than the file has to say so. On the
     * Plugins page the same choice routes an INSTALL, and a row naming only a
     * config file under-describes what it does.
     */
    public function testAPageCanAddWhatElseTheScopeDecides(): void
    {
        $row = $this->row()->row(also: 'new plugins install here too');

        $this->assertNotNull($row);
        $this->assertSame('for this session · new plugins install here too', $row->hint);
    }

    /** And a page where it decides only the file says only that. */
    public function testWithoutAnExtraMeaningTheHintStaysTheSessionNote(): void
    {
        $this->assertSame('for this session', $this->row()->row()?->hint);
    }

    /**
     * A chooser offering one option cannot be wrong and cannot be useful. With
     * no home there is exactly one writable file.
     */
    public function testThereIsNoRowWhenThereIsNothingToChoose(): void
    {
        $this->assertNull($this->rowWithoutAHome()->row());
    }

    public function testLeadPutsTheChoiceAboveThePagesOwnRows(): void
    {
        $own = new SettingsRow(key: 'theme', label: 'Theme', kind: SettingsRowKind::Bool, value: true, restart: false);

        $rows = $this->row()->lead([$own]);

        $this->assertSame([SettingsScopeRow::KEY, 'theme'], array_map(static fn (SettingsRow $r): string => $r->key, $rows));
    }

    /** With no row to lead with, a page's rows are handed back untouched. */
    public function testLeadIsATransparentPassThroughWhenThereIsNoRow(): void
    {
        $own = new SettingsRow(key: 'theme', label: 'Theme', kind: SettingsRowKind::Bool, value: true, restart: false);

        $this->assertSame([$own], $this->rowWithoutAHome()->lead([$own]));
    }

    public function testChoosingAScopeMovesWhereWritesLand(): void
    {
        $project = new TempProject([]);
        $home = new TempProject([]);
        $files = new ConfigFiles($project->dir, $home->dir);
        $scope = new SettingsScope($files);
        $row = new SettingsScopeRow($scope, $files, new Translator('en'));

        $row->change('project');

        $this->assertSame(ConfigScope::Project, $scope->current());
        $this->assertSame('project', $row->row()?->value, 'and the row shows it');
    }

    /**
     * The stepper can only produce the options offered, but `change()` is also
     * reachable from a keymap-driven edit — a value that names no scope must
     * leave the choice alone rather than resolve to a default.
     */
    public function testAnUnrecognisedValueLeavesTheScopeAlone(): void
    {
        $project = new TempProject([]);
        $home = new TempProject([]);
        $files = new ConfigFiles($project->dir, $home->dir);
        $scope = new SettingsScope($files);
        // Moved OFF the default first. Asserting "unchanged" from the default
        // is vacuous: a fallback-to-a-default bug produces the same answer.
        $scope->set(ConfigScope::Project);

        new SettingsScopeRow($scope, $files, new Translator('en'))->change('somewhere-else');

        $this->assertSame(ConfigScope::Project, $scope->current());
    }

    public function testItOwnsOnlyItsOwnKey(): void
    {
        $row = $this->row();

        $this->assertTrue($row->owns(SettingsScopeRow::KEY));
        $this->assertFalse($row->owns('theme'));
    }
}
