<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\ContextMenu;
use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Full-app plugin scenarios: a fixture plugin registering a keyed command, a
 * menu entry, a save hook and a deliberately throwing command — plus loader
 * containment and the global plugin directory.
 */
final class IdePluginTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    private ?string $portaHome = null;

    protected function setUp(): void
    {
        $portaHome = \getenv('PORTA_HOME');
        $this->portaHome = $portaHome ? $portaHome : null;
    }

    protected function tearDown(): void
    {
        \putenv(null !== $this->portaHome ? 'PORTA_HOME='.$this->portaHome : 'PORTA_HOME');
    }

    /** The demo fixture: a keyed insert command, a thrower, a menu item and a save hook. */
    private function demoPlugin(): string
    {
        return <<<'PHP'
            <?php
            use Porta\Plugin\Api\PluginApi;
            use Porta\Plugin\Api\PortaPlugin;

            return new class implements PortaPlugin {
                public function name(): string
                {
                    return 'demo';
                }

                public function activate(PluginApi $api): void
                {
                    $api->registerCommand('hello', 'Say Hello', function () use ($api): void {
                        $api->insertAtCaret('PLUGGED');
                        $api->flash('hello ran in '.basename((string) $api->activeFile()));
                    }, ['ctrl+alt+y']);
                    $api->registerCommand('boom', 'Go Boom', static function (): void {
                        throw new \RuntimeException('kaboom');
                    }, ['ctrl+alt+u']);
                    $api->addMenuItem('hello');
                    $api->onFileSaved(static function (string $path): void {
                        file_put_contents($path.'.hooked', 'yes'); // observable side effect
                    });
                }
            };
            PHP;
    }

    public function testPluginCommandKeyMenuSaveHookAndContainment(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta/plugins/demo/plugin.php' => $this->demoPlugin(),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function (Tui $tui, $terminal): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Plugins'));
                    $this->frame($tui);
                    $this->assertStringContainsString('Plugins', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the Plugins menu title is in the bar');
                    $tui->handleInput("\x1b\x19"); // ctrl+alt+y (legacy: ESC + ctrl+y) → demo:hello
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('PLUGGED', $screen, 'the plugin inserted at the caret');
                    $this->assertStringContainsString('hello ran in a.php', $screen, 'action methods reach the live IDE');
                },
                340 => function (Tui $tui, VirtualTerminal $terminal, $ide) use ($project): void {
                    $tui->handleInput("\x13"); // ctrl+s → save → the plugin hook fires
                    $this->frame($tui);
                    $this->assertSame('yes', (string) \file_get_contents($project->path('a.php.hooked')), 'the onFileSaved hook ran');
                    $tui->handleInput("\x1b\x15"); // ctrl+alt+u → demo:boom (throws)
                    $this->awaitPainted($tui, $terminal, 'Plugin "demo" failed: kaboom');
                    $this->assertStringContainsString('Plugin "demo" failed: kaboom', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the throw became a flash');
                    // …and the IDE is still alive: the next keystroke lands in the editor.
                    $tui->handleInput('Z');
                },
                480 => function ($tui): void {
                    $this->assertStringContainsString('Z', $tui->getById('editor')->getDocument()->getText(), 'input still works after a plugin fault');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testPaletteListsPluginCommandsAndUserRebindsDispatch(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta/plugins/demo/plugin.php' => $this->demoPlugin(),
            '.porta.json' => (string) \json_encode(['keymap' => ['demo:hello' => ['f8']]]),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a → command palette
                    $this->frame($tui);
                    foreach (\str_split('Say') as $char) {
                        $tui->handleInput($char);
                    }
                    $this->awaitPainted($tui, $terminal, 'Say Hello');
                    $this->assertStringContainsString('Say Hello', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the palette lists the plugin command');
                    $tui->handleInput("\x1b"); // close the palette
                },
                380 => function (Tui $tui, $terminal): void {
                    // F8 is a core debug key, but its `when` guard is false while no
                    // session runs — the key falls through to the user's plugin rebind.
                    $tui->handleInput("\x1b[19~");
                    $this->frame($tui);
                    $this->assertStringContainsString('PLUGGED', $tui->getById('editor')->getDocument()->getText(), 'the .porta.json rebind dispatched');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGlobalPluginsLoadAndProjectWinsOnCollision(): void
    {
        \putenv('PATH=/nonexistent');
        $global = new TempProject([
            '.porta/plugins/demo/plugin.php' => \str_replace(["'demo'", "'hello ran in '"], ["'globaldemo'", "'GLOBAL ran in '"], $this->demoPlugin()),
            '.porta/plugins/wave/plugin.php' => <<<'PHP'
                <?php
                use Porta\Plugin\Api\PluginApi;
                use Porta\Plugin\Api\PortaPlugin;

                return new class implements PortaPlugin {
                    public function name(): string
                    {
                        return 'wave';
                    }

                    public function activate(PluginApi $api): void
                    {
                        $api->registerCommand('hi', 'Wave Hi', function () use ($api): void {
                            $api->flash('wave!');
                        }, ['ctrl+alt+w']);
                    }
                };
                PHP,
        ]);
        \putenv('PORTA_HOME='.$global->dir);
        $project = new TempProject([
            '.porta/plugins/demo/plugin.php' => $this->demoPlugin(),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function (Tui $tui, $terminal): void {
                    $tui->handleInput("\x1b\x19"); // ctrl+alt+y → the PROJECT demo wins
                    $this->frame($tui); // render its flash before the next one replaces it
                    $tui->handleInput("\x1b\x17"); // ctrl+alt+w → the global-only plugin
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('hello ran in a.php', $screen, 'the project copy shadowed the global one');
                    $this->assertStringNotContainsString('GLOBAL ran in', $screen);
                    $this->assertStringContainsString('wave!', $screen, 'non-colliding global plugins load');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testABrokenPluginWarnsAtBootAndTheIdeStaysFunctional(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta/plugins/broken/plugin.php' => '<?php not valid php {{{',
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function (Tui $tui, $terminal): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Plugin "broken" failed to load'));
                    $this->frame($tui);
                    $this->assertStringContainsString('Plugin "broken" failed to load', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput('X');
                },
                340 => function ($tui): void {
                    $this->assertStringContainsString('X', $tui->getById('editor')->getDocument()->getText(), 'the IDE booted fine regardless');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testReadyFiresBeforeTheFirstFileOpenedAndShutdownLast(): void
    {
        // The ordering IS the contract: a plugin that sets something up in
        // onReady must be able to rely on it being there when the first
        // fileOpened arrives, rather than racing the initial open. The log is
        // written from onShutdown, which also proves that hook runs at all —
        // and that it can still reach the filesystem while core tears down.
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta/plugins/lifecycle/plugin.php' => <<<'PHP'
                <?php
                use Porta\Plugin\Api\PluginApi;
                use Porta\Plugin\Api\PortaPlugin;

                return new class implements PortaPlugin {
                    /** @var list<string> */
                    private array $order = [];

                    public function name(): string
                    {
                        return 'lifecycle';
                    }

                    public function activate(PluginApi $api): void
                    {
                        $this->order[] = 'activate';
                        $api->onReady(function (string $root): void {
                            $this->order[] = 'ready';
                        });
                        $api->onFileOpened(function (string $path): void {
                            $this->order[] = 'opened:'.basename($path);
                        });
                        $api->onShutdown(function (string $root): void {
                            file_put_contents($root.'/lifecycle.log', implode(',', [...$this->order, 'shutdown']));
                        });
                    }
                };
                PHP,
        ]);

        $this->runIde($project, $project->path('a.php'), [200 => function (Tui $tui): void {
            $this->frame($tui);
        }]);

        $this->assertSame(
            'activate,ready,opened:a.php,shutdown',
            (string) \file_get_contents($project->path('lifecycle.log')),
            'ready lands after activate and before the first open; shutdown is last',
        );
    }

    public function testAPluginCommandCanLandInTheEditorContextMenu(): void
    {
        // The seam a file-scoped plugin verb wants: the Plugins menu is a
        // global list, and "do this to the file I am looking at" belongs where
        // the user is pointing.
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n",
            '.porta/plugins/ctx/plugin.php' => <<<'PHP'
                <?php
                use Porta\Plugin\Api\PluginApi;
                use Porta\Plugin\Api\PortaPlugin;
                use Porta\Workbench\Menu\MenuSurface;

                return new class implements PortaPlugin {
                    public function name(): string
                    {
                        return 'ctx';
                    }

                    public function activate(PluginApi $api): void
                    {
                        $api->registerCommand('here', 'Do It Here', static function (): void {});
                        $api->registerCommand('anywhere', 'Do It Anywhere', static function (): void {});
                        $api->addMenuItem('here', surface: MenuSurface::EditorContext);
                        $api->addMenuItem('anywhere');
                    }
                };
                PHP,
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, $terminal, Ide $ide): void {
                    $this->frame($tui);
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('editor'));
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 1)); // right-click
                    $this->frame($tui);

                    // The menu floats via the overlay and occupies no layout
                    // row, so its rows come from segments(), not render().
                    $widget = Services::resolve($ide, ContextMenu::class);
                    $menu = \implode("\n", \array_map(
                        static fn (array $row): string => AnsiUtils::stripAnsiCodes($row['segment']),
                        $widget->segments($widget->panelWidth()),
                    ));
                    $this->assertStringContainsString('Do It Here', $menu, "the plugin's editor-context item is offered");
                    $this->assertStringNotContainsString('Do It Anywhere', $menu, 'and a Plugins-menu item stays out of it');
                    $this->assertStringContainsString('Cut', $menu, "core's own items are still first");
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAPluginCommandOnTheFileSurfaceLearnsWhichFileWasClicked(): void
    {
        // The file surface is the one that carries a PATH: right-clicking
        // other.php in the tree while editing a.php has to answer about
        // other.php, so contextFile() — not activeFile() — is what the handler
        // reads, and it falls back to the active file everywhere else.
        \putenv('PATH=/nonexistent');
        $project = new TempProject([
            'a.php' => "<?php\n",
            'other.php' => "<?php\n",
            '.porta/plugins/filectx/plugin.php' => <<<'PHP'
                <?php
                use Porta\Plugin\Api\PluginApi;
                use Porta\Plugin\Api\PortaPlugin;
                use Porta\Workbench\Menu\MenuSurface;

                return new class implements PortaPlugin {
                    public function name(): string
                    {
                        return 'filectx';
                    }

                    public function activate(PluginApi $api): void
                    {
                        $api->registerCommand('which', 'Which File', static function () use ($api): void {
                            $api->flash('acted on '.basename((string) $api->contextFile()));
                        });
                        $api->addMenuItem('which', surface: MenuSurface::FileContext);
                    }
                };
                PHP,
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->frame($tui);
                    $renderer = Services::resolve($ide, Renderer::class);

                    // The tree row for other.php — a file we are NOT editing.
                    $tree = $tui->getById('tree');
                    $treeRect = $renderer->getWidgetRect($tree);
                    $row = null;
                    foreach (Paint::lines($tree, new RenderContext($treeRect->columns, $treeRect->rows)) as $i => $line) {
                        if (!\str_contains(AnsiUtils::stripAnsiCodes($line), 'other.php')) {
                            continue;
                        }
                        $row = $i;
                    }
                    $this->assertNotNull($row, 'other.php is in the tree');

                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $treeRect->col + 3, $treeRect->row + $row + 1));
                    $this->frame($tui);

                    $widget = Services::resolve($ide, ContextMenu::class);
                    /** @var list<MenuItem> $items */
                    $items = Probe::get($widget, 'items');
                    $index = null;
                    foreach ($items as $i => $item) {
                        if ('Which File' !== $item->label) {
                            continue;
                        }
                        $index = $i;
                    }
                    $this->assertNotNull($index, "the tree row offers the plugin's item");

                    $widget->runItem($index);
                    $this->awaitPainted($tui, $terminal, 'acted on other.php');
                    $this->assertStringContainsString('acted on other.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the handler saw the CLICKED file, not the active one');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
