<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Full-app plugin scenarios: a fixture plugin registering a keyed command, a
 * menu entry, a save hook and a deliberately throwing command — plus loader
 * containment and the global plugin directory.
 */
final class IdePluginTest extends TestCase
{
    use RunsIde;

    private string $originalPath;
    private ?string $portaHome = null;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        $portaHome = getenv('PORTA_HOME');
        $this->portaHome = $portaHome ? $portaHome : null;
        putenv('PORTA_LSP');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv(null !== $this->portaHome ? 'PORTA_HOME='.$this->portaHome : 'PORTA_HOME');
    }

    /** The demo fixture: a keyed insert command, a thrower, a menu item and a save hook. */
    private function demoPlugin(): string
    {
        return <<<'PHP'
            <?php
            use Porta\Plugin\PluginApi;
            use Porta\Plugin\PortaPlugin;

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
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta/plugins/demo/plugin.php' => $this->demoPlugin(),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Plugins'));
                    $tui->tick();
                    $this->assertStringContainsString('Plugins', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the Plugins menu title is in the bar');
                    $tui->handleInput("\x1b\x19"); // ctrl+alt+y (legacy: ESC + ctrl+y) → demo:hello
                    $tui->tick();
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('PLUGGED', $screen, 'the plugin inserted at the caret');
                    $this->assertStringContainsString('hello ran in a.php', $screen, 'action methods reach the live IDE');
                },
                340 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->handleInput("\x13"); // ctrl+s → save → the plugin hook fires
                    $tui->tick();
                    $this->assertSame('yes', (string) file_get_contents($project->path('a.php.hooked')), 'the onFileSaved hook ran');
                    $tui->handleInput("\x1b\x15"); // ctrl+alt+u → demo:boom (throws)
                    $tui->tick();
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
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta/plugins/demo/plugin.php' => $this->demoPlugin(),
            '.porta.json' => (string) json_encode(['keymap' => ['demo:hello' => ['f8']]]),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b[97;6u"); // ctrl+shift+a → command palette
                    $tui->tick();
                    foreach (str_split('Say') as $char) {
                        $tui->handleInput($char);
                    }
                    $tui->tick();
                    $this->assertStringContainsString('Say Hello', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the palette lists the plugin command');
                    $tui->handleInput("\x1b"); // close the palette
                },
                380 => function ($tui, $terminal): void {
                    // F8 is a core debug key, but its `when` guard is false while no
                    // session runs — the key falls through to the user's plugin rebind.
                    $tui->handleInput("\x1b[19~");
                    $tui->tick();
                    $this->assertStringContainsString('PLUGGED', $tui->getById('editor')->getDocument()->getText(), 'the .porta.json rebind dispatched');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGlobalPluginsLoadAndProjectWinsOnCollision(): void
    {
        putenv('PATH=/nonexistent');
        $global = new TempProject([
            '.porta/plugins/demo/plugin.php' => str_replace(["'demo'", "'hello ran in '"], ["'globaldemo'", "'GLOBAL ran in '"], $this->demoPlugin()),
            '.porta/plugins/wave/plugin.php' => <<<'PHP'
                <?php
                use Porta\Plugin\PluginApi;
                use Porta\Plugin\PortaPlugin;

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
        putenv('PORTA_HOME='.$global->dir);
        $project = new TempProject([
            '.porta/plugins/demo/plugin.php' => $this->demoPlugin(),
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x19"); // ctrl+alt+y → the PROJECT demo wins
                    $tui->tick(); // render its flash before the next one replaces it
                    $tui->handleInput("\x1b\x17"); // ctrl+alt+w → the global-only plugin
                    $tui->tick();
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
        putenv('PATH=/nonexistent');
        $project = new TempProject([
            '.porta/plugins/broken/plugin.php' => '<?php not valid php {{{',
            'a.php' => "<?php\n",
        ]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal): void {
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Plugin "broken" failed to load'));
                    $tui->tick();
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
}
