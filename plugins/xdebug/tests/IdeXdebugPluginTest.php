<?php

declare(strict_types=1);

namespace Porta\Xdebug\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UntrustsProjects;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The plugin through a real IDE: the adapter it finds is the one the
 * debugger starts, and the Settings page's button is wired to its installer.
 */
final class IdeXdebugPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use PreservesPortaHome;
    use RunsIde;
    use UntrustsProjects;
    use UsesBundledPlugins;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        $this->restorePristinePortaHome();
    }

    /**
     * A project the user has not trusted names the program the debugger
     * runs: the node binary and the adapter script both come from its own
     * .porta.json. Neither may be honoured — the user's own copies run.
     */
    public function testARestrictedProjectCannotChooseWhatTheDebuggerRuns(): void
    {
        $this->untrustProjects();
        $this->untrustingHome->write('.porta/config.json', '{"enabledPlugins":["xdebug"]}');
        $this->untrustingHome->write('.cursor/extensions/xdebug.php-debug-1.38.0/out/phpDebug.js', '');
        $project = new TempProject([
            'evil.js' => '// the project\'s own',
            'a.php' => "<?php\n",
        ]);
        $project->write('.porta.json', \json_encode(['plugins' => ['xdebug' => ['nodeBinary' => $project->path('evil-node'), 'adapterPath' => $project->path('evil.js')]]], \JSON_THROW_ON_ERROR));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->assertSame(
                        ['php' => ['node', $this->untrustingHome->path('.cursor/extensions/xdebug.php-debug-1.38.0/out/phpDebug.js')]],
                        Services::resolve($ide, PluginHost::class)->manager->debugAdapters(),
                        'the project\'s node and adapter are dropped',
                    );
                },
            ],
        );
    }

    public function testTheFoundAdapterIsPhpsAndTheButtonInstallsOne(): void
    {
        $home = new TempProject(['.cursor/extensions/xdebug.php-debug-1.38.0/out/phpDebug.js' => '']);
        \putenv('PORTA_HOME='.$home->dir);
        $project = new TempProject([
            '.porta.json' => '{"enabledPlugins":["xdebug"],"plugins":{"xdebug":{"nodeBinary":"/opt/node/bin/node"}}}',
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($home): void {
                    $manager = Services::resolve($ide, PluginHost::class)->manager;
                    $this->assertSame(
                        ['php' => ['/opt/node/bin/node', $home->path('.cursor/extensions/xdebug.php-debug-1.38.0/out/phpDebug.js')]],
                        $manager->debugAdapters(),
                        'php\'s adapter: the editor\'s copy, run with the node the setting names',
                    );
                    [$installer] = $manager->registeredInstallers('xdebug');
                    $this->assertSame([['self', 'install']], $installer['via']);
                    $this->assertTrue($installer['available']());
                    $this->assertSame('1.38.0 (.cursor)', ($installer['describe'] ?? static fn (): string => '')());

                    \unlink($home->path('.cursor/extensions/xdebug.php-debug-1.38.0/out/phpDebug.js'));
                    $this->assertSame([], $manager->debugAdapters(), 'with none found, php has no adapter from here');
                    $this->assertFalse($installer['available']());

                    // The button runs the plugin's command: with no curl here,
                    // it fails, and says so.
                    Services::resolve($ide, CommandRegistry::class)->run('xdebug:install');
                    $this->awaitPainted($tui, $terminal, 'curl is not available');
                },
            ],
        );
    }
}
