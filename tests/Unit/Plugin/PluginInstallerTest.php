<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Plugin\PluginInstaller;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Process\ExecutableFinder;

final class PluginInstallerTest extends TestCase
{
    private TempProject $project;
    private TempProject $global;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->global = new TempProject();
    }

    private function installer(?string $globalBase = 'own', ?string $git = null): PluginInstaller
    {
        return new PluginInstaller($this->project->dir, 'own' === $globalBase ? $this->global->dir : $globalBase, new Translator('en'), $git);
    }

    /** Runs install() and pumps the loop until the callback fires; returns [error, file]. */
    private function install(PluginInstaller $installer, string $source, string $scope = 'project'): array
    {
        $result = null;
        $installer->install($source, $scope, static function (?string $error, ?string $file) use (&$result): void {
            $result = [$error, $file];
        });
        $elapsed = 0.0;
        while (null === $result && $elapsed < 5.0) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, static fn () => $suspension->resume());
            $suspension->suspend();
            $elapsed += 0.02;
        }
        $this->assertNotNull($result, 'the install callback fired');

        return $result;
    }

    /** A plugin source dir outside any scope base. */
    private function sourceDir(string $name, bool $withPluginPhp = true): string
    {
        $dir = $this->project->dir.'/src-plugins/'.$name;
        mkdir($dir, 0777, true);
        if ($withPluginPhp) {
            file_put_contents($dir.'/plugin.php', "<?php\n// {$name}\n");
        }

        return $dir;
    }

    private function git(): string
    {
        $git = new ExecutableFinder()->find('git');
        if (null === $git) {
            self::markTestSkipped('git binary not available');
        }

        return $git;
    }

    /** A local git repo usable as a clone URL. */
    private function repo(bool $withPluginPhp): string
    {
        $dir = $this->sourceDir('repo-plugin', $withPluginPhp);
        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>/dev/null && git -C {$q} config user.email t@t && git -C {$q} config user.name t && git -C {$q} add -A && git -C {$q} commit -qm init --allow-empty 2>/dev/null");

        return $dir;
    }

    public function testCopyInstallLandsInTheChosenScope(): void
    {
        $source = $this->sourceDir('demo');
        [$error, $file] = $this->install($this->installer(), $source);
        $this->assertNull($error);
        $this->assertSame($this->project->dir.'/.porta/plugins/demo/plugin.php', $file);
        $this->assertFileExists((string) $file);

        [$error, $file] = $this->install($this->installer(), $source, 'global');
        $this->assertNull($error);
        $this->assertSame($this->global->dir.'/.porta/plugins/demo/plugin.php', $file);
    }

    public function testCopyGuards(): void
    {
        [$error] = $this->install($this->installer(), $this->sourceDir('empty', withPluginPhp: false));
        $this->assertStringContainsString('No plugin.php found in', (string) $error);

        $source = $this->sourceDir('demo');
        $this->install($this->installer(), $source);
        [$error] = $this->install($this->installer(), $source);
        $this->assertSame('Plugin "demo" is already installed', $error);

        [$error] = $this->install($this->installer(), $this->sourceDir('.hidden'));
        $this->assertSame('".hidden" is not a valid plugin name', $error);

        [$error] = $this->install($this->installer(globalBase: null), $source, 'global');
        $this->assertSame('Cannot install globally (no home directory)', $error);
    }

    public function testCopyFailureCleansUpTheDestination(): void
    {
        // The plugins base is an existing *file*: mirror() cannot create the dir.
        mkdir($this->project->dir.'/.porta', 0777, true);
        file_put_contents($this->project->dir.'/.porta/plugins', '');
        [$error] = $this->install($this->installer(), $this->sourceDir('demo'));
        $this->assertStringContainsString('Cannot install into', (string) $error);
        $this->assertFileDoesNotExist($this->project->dir.'/.porta/plugins/demo');
    }

    public function testUrlSourcesRequireGitAndDeriveTheNameFromTheUrl(): void
    {
        [$error] = $this->install($this->installer(git: null), 'https://example.com/x/demo.git');
        $this->assertSame('git is not available', $error);

        // Name derivation: trailing slash and .git suffix are stripped before validation.
        [$error] = $this->install($this->installer(git: null), 'https://example.com/x/-bad-name.git/');
        $this->assertSame('"-bad-name" is not a valid plugin name', $error);
    }

    public function testCloneInstallsAndRejectsAConcurrentOne(): void
    {
        $git = $this->git();
        $repo = $this->repo(withPluginPhp: true);

        // A plain local path would take the copy branch, so use file://.
        $installer = $this->installer(git: $git);
        $first = null;
        $installer->install('file://'.$repo, 'project', static function (?string $error, ?string $file) use (&$first): void {
            $first = [$error, $file];
        });
        $second = null;
        $installer->install('file://'.$repo, 'project', static function (?string $error) use (&$second): void {
            $second = $error;
        });
        $this->assertSame('Another install is already running', $second, 'single-flight while the clone runs');

        $elapsed = 0.0;
        while (null === $first && $elapsed < 5.0) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, static fn () => $suspension->resume());
            $suspension->suspend();
            $elapsed += 0.02;
        }
        $this->assertSame([null, $this->project->dir.'/.porta/plugins/repo-plugin/plugin.php'], $first);
        $this->assertFileExists($this->project->dir.'/.porta/plugins/repo-plugin/plugin.php');
    }

    public function testCloneFailureAndPluginlessRepoAreRemoved(): void
    {
        $git = $this->git();

        [$error] = $this->install($this->installer(git: $git), 'file:///nonexistent/nowhere');
        $this->assertStringContainsString('git clone failed (exit code', (string) $error);
        $this->assertDirectoryDoesNotExist($this->project->dir.'/.porta/plugins/nowhere');

        $repo = $this->repo(withPluginPhp: false);
        [$error] = $this->install($this->installer(git: $git), 'file://'.$repo);
        $this->assertSame('The repository contains no plugin.php', $error);
        $this->assertDirectoryDoesNotExist($this->project->dir.'/.porta/plugins/repo-plugin');
    }

    public function testRemoveAndItsErrors(): void
    {
        $installer = $this->installer();
        $this->install($installer, $this->sourceDir('demo'));
        $this->assertNull($installer->remove('demo', 'project'));
        $this->assertDirectoryDoesNotExist($this->project->dir.'/.porta/plugins/demo');

        $this->assertNull($installer->remove('gone', 'project'), 'removing a missing dir is a quiet success');
        $this->assertSame('Cannot remove "x"', $this->installer(globalBase: null)->remove('x', 'global'));
    }

    public function testEmbeddedPluginsListNonRemovableAndRefuseRemoval(): void
    {
        $embedded = new TempProject(['bundled/plugin.php' => "<?php\n"]);
        $installer = new PluginInstaller($this->project->dir, $this->global->dir, new Translator('en'), null, $embedded->dir);
        $this->install($installer, $this->sourceDir('mine'));

        $this->assertSame(
            [
                ['name' => 'mine', 'scope' => 'project', 'dir' => $this->project->dir.'/.porta/plugins/mine'],
                ['name' => 'bundled', 'scope' => 'embedded', 'dir' => $embedded->dir.'/bundled'],
            ],
            $installer->installed(),
        );

        $this->assertSame('Built-in plugins cannot be removed', $installer->remove('bundled', 'embedded'));
        $this->assertDirectoryExists($embedded->dir.'/bundled');
    }

    public function testAPortaDevCheckoutClassifiesItsProjectPluginsAsEmbedded(): void
    {
        // rootDir/.porta/plugins IS the embedded dir (developing Porta itself).
        mkdir($this->project->dir.'/.porta/plugins/bundled', 0777, true);
        file_put_contents($this->project->dir.'/.porta/plugins/bundled/plugin.php', "<?php\n");
        $installer = new PluginInstaller($this->project->dir, null, new Translator('en'), null, $this->project->dir.'/.porta/plugins');

        $this->assertSame(
            [['name' => 'bundled', 'scope' => 'embedded', 'dir' => $this->project->dir.'/.porta/plugins/bundled']],
            $installer->installed(),
            'listed once, as embedded — not as a removable project plugin',
        );
    }

    public function testInstalledListsBothScopesProjectFirst(): void
    {
        $installer = $this->installer();
        $this->assertSame([], $installer->installed());

        $source = $this->sourceDir('both');
        $this->install($installer, $source);
        $this->install($installer, $source, 'global');
        $this->install($installer, $this->sourceDir('worldwide'), 'global');

        $this->assertSame(
            [
                ['name' => 'both', 'scope' => 'project', 'dir' => $this->project->dir.'/.porta/plugins/both'],
                ['name' => 'both', 'scope' => 'global', 'dir' => $this->global->dir.'/.porta/plugins/both'],
                ['name' => 'worldwide', 'scope' => 'global', 'dir' => $this->global->dir.'/.porta/plugins/worldwide'],
            ],
            $installer->installed(),
        );

        $this->assertSame(
            [['name' => 'both', 'scope' => 'project', 'dir' => $this->project->dir.'/.porta/plugins/both']],
            $this->installer(globalBase: null)->installed(),
            'a null global base lists only the project scope',
        );
    }
}
