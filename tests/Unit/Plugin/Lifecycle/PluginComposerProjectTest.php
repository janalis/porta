<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Lifecycle;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Lifecycle\PluginComposerProject;
use Porta\Support\JsonFile;
use Porta\Tests\Support\TempProject;

final class PluginComposerProjectTest extends TestCase
{
    private TempProject $scope;
    private PluginComposerProject $project;

    protected function setUp(): void
    {
        $this->scope = new TempProject();
        $this->project = new PluginComposerProject();
    }

    public function testEnsureScaffoldCreatesAComposerJsonOnlyOnce(): void
    {
        $dir = $this->project->ensureScaffold($this->scope->dir);
        $this->assertSame($this->scope->dir.'/.porta', $dir);
        $path = $dir.'/composer.json';
        $this->assertFileExists($path);

        $written = JsonFile::read($path);
        $this->assertSame('porta/local-plugins', $written['name'] ?? null);
        $this->assertSame('project', $written['type'] ?? null);
        $this->assertSame([], $written['repositories'] ?? null);
        $this->assertSame([], $written['require'] ?? null);

        // A second call is idempotent — it must not clobber an entry a
        // require/repository call already added.
        file_put_contents($path, (string) json_encode(['name' => 'porta/local-plugins', 'require' => ['acme/x' => '^1.0']]));
        $this->project->ensureScaffold($this->scope->dir);
        $this->assertSame(['acme/x' => '^1.0'], JsonFile::read($path)['require'] ?? null, 'scaffold left an existing file untouched');
    }

    public function testPackageDirIsComposersDefaultVendorLayout(): void
    {
        $this->assertSame($this->scope->dir.'/.porta/vendor/acme/mine', $this->project->packageDir($this->scope->dir, 'acme/mine'));
    }

    public function testAddPathRepositoryMergesIntoExistingRepositoriesWithoutClobbering(): void
    {
        $this->project->ensureScaffold($this->scope->dir);
        $this->project->addVcsRepository($this->scope->dir, 'acme/other', 'https://example.com/other.git');
        $this->project->addPathRepository($this->scope->dir, 'acme/mine', '/src/acme-mine');

        $repositories = JsonFile::read($this->scope->dir.'/.porta/composer.json')['repositories'] ?? null;
        $this->assertSame(
            [
                'acme/other' => ['type' => 'vcs', 'url' => 'https://example.com/other.git'],
                'acme/mine' => ['type' => 'path', 'url' => '/src/acme-mine', 'options' => ['symlink' => true]],
            ],
            $repositories,
        );
    }

    public function testAddingTheSamePackageAgainOverwritesRatherThanAccumulating(): void
    {
        $this->project->ensureScaffold($this->scope->dir);
        $this->project->addPathRepository($this->scope->dir, 'acme/mine', '/first/path');
        $this->project->addPathRepository($this->scope->dir, 'acme/mine', '/second/path');

        $repositories = JsonFile::read($this->scope->dir.'/.porta/composer.json')['repositories'] ?? null;
        $this->assertSame(['acme/mine' => ['type' => 'path', 'url' => '/second/path', 'options' => ['symlink' => true]]], $repositories);
    }

    public function testAddRepositoryWritesAComposerJsonEvenWithoutAPriorEnsureScaffoldCall(): void
    {
        // In production ensureScaffold() always runs first (see
        // PluginInstaller), but addPathRepository()/addVcsRepository() do not
        // depend on that ordering — they read-or-default, so calling one alone
        // still lands a readable repositories entry.
        $this->project->addPathRepository($this->scope->dir, 'acme/mine', '/src/acme-mine');

        $path = $this->scope->dir.'/.porta/composer.json';
        $this->assertFileExists($path);
        $repositories = JsonFile::read($path)['repositories'] ?? null;
        $this->assertSame(['acme/mine' => ['type' => 'path', 'url' => '/src/acme-mine', 'options' => ['symlink' => true]]], $repositories);
    }
}
