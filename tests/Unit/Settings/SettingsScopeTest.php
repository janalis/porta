<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Settings\SettingsScope;
use Porta\Tests\Support\TempProject;

/**
 * Where the settings screen writes, and — the part that can surprise someone —
 * what it defaults to.
 */
final class SettingsScopeTest extends TestCase
{
    /** Held so the destructor does not remove the dir mid-test. */
    private TempProject $project;

    private TempProject $home;

    private function scope(?string $projectJson): SettingsScope
    {
        $this->project = new TempProject(null === $projectJson ? [] : ['.porta.json' => $projectJson]);
        $this->home = new TempProject([]);

        return new SettingsScope(new ConfigFiles($this->project->dir, $this->home->dir));
    }

    /**
     * A theme is a preference, and writing it into a tracked file is the thing
     * this whole scope exists to stop.
     */
    public function testANewUserWritesGlobally(): void
    {
        $this->assertSame(ConfigScope::Global, $this->scope(null)->current());
    }

    /**
     * Someone whose project file already configures settings keeps writing
     * there. Their next edit landing somewhere new — while the old values sit
     * visibly in .porta.json, still overriding it — is the surprising outcome,
     * not the safe one.
     */
    public function testAProjectThatAlreadyHasSettingsKeepsWritingToItself(): void
    {
        $json = \json_encode(['settings' => ['theme' => 'light']], \JSON_THROW_ON_ERROR);

        $this->assertSame(ConfigScope::Project, $this->scope($json)->current());
    }

    /**
     * Presence is the question, and this is the one place it is the right one:
     * it asks "has this project been configured", not "what is this value".
     * An empty block is not a configured project.
     */
    public function testAnEmptySettingsBlockIsNotAConfiguredProject(): void
    {
        $json = \json_encode(['settings' => new \stdClass()], \JSON_THROW_ON_ERROR);

        $this->assertSame(ConfigScope::Global, $this->scope($json)->current());
    }

    /** A project file that configures something ELSE is not a settings project. */
    public function testAProjectFileWithoutASettingsBlockStillDefaultsToGlobal(): void
    {
        $json = \json_encode(['keymap' => ['save' => ['ctrl+s']]], \JSON_THROW_ON_ERROR);

        $this->assertSame(ConfigScope::Global, $this->scope($json)->current());
    }

    /**
     * With no home there is no global file, and defaulting to a scope whose
     * writes would all fail is worse than defaulting to the one that works.
     */
    public function testWithoutAHomeTheDefaultIsTheOnlyWritableScope(): void
    {
        $this->project = new TempProject([]);

        $scope = new SettingsScope(new ConfigFiles($this->project->dir));

        $this->assertSame(ConfigScope::Project, $scope->current());
    }

    public function testTheChoiceCanBeChangedForTheSession(): void
    {
        $scope = $this->scope(null);

        $scope->set(ConfigScope::Project);

        $this->assertSame(ConfigScope::Project, $scope->current());
    }
}
