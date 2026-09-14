<?php

declare(strict_types=1);

namespace Porta\Todo\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Workbench\Dock\BottomDock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Tui;

/**
 * The TODO scan through a real IDE, now that it is a plugin — and above all,
 * that moving it out cost the user nothing: the key, the menu entry and an
 * existing `.porta.json` rebind all still work, because the plugin ADOPTED
 * core's `ide_todo` id instead of taking a namespaced one.
 */
final class IdeTodoPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    /** @param array<string, string> $files */
    private static function project(array $files, string $porta = '{"enabledPlugins":["todo"]}'): TempProject
    {
        return new TempProject([...$files, '.porta.json' => $porta]);
    }

    public function testAltEightScansAndEnterJumpsToTheMarker(): void
    {
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // real grep for the async scan
        $project = self::project([
            'src/A.php' => "<?php\n// TODO extract this\n",
            'src/B.php' => "<?php\n// FIXME races\n",
        ]);

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                200 => function (Tui $tui, $terminal): void {
                    $tui->handleInput("\x1b8"); // alt+8 — core's key, the plugin's handler
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'FIXME races'));
                    $this->frame($tui);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('TODO extract this', $screen, 'the scan found both markers');
                    $this->assertStringContainsString('2 results', $screen);
                    $tui->handleInput("\r");
                },
                400 => function (Tui $tui, $terminal) use ($project): void {
                    $this->frame($tui);
                    // grep walks the directory in its own order, so either row
                    // may be the selected one; both are TODO hits.
                    $this->assertMatchesRegularExpression('~src/[AB]\.php$~', (string) $tui->getById('editor')->getFilePath(), 'Enter took the default open-and-jump');

                    // F5 re-runs the tool's own rerun closure.
                    $project->write('src/C.php', "<?php\n// TODO added later\n");
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\x1b[15~");
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '3 results'));
                    $this->assertStringContainsString('TODO added later', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the rerun saw the new file');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAnExistingKeymapRebindOfTheCoreIdStillFires(): void
    {
        // The whole reason adoption exists. A user who rebound `ide_todo`
        // before the feature moved must not discover that their key is now an
        // "unknown keymap action" — the id is core's, only the code moved.
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH);
        $project = self::project(
            ['a.php' => "<?php\n// TODO rebound\n"],
            '{"enabledPlugins":["todo"],"keymap":{"ide_todo":"f9"}}',
        );

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b[20~"); // F9
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'TODO rebound'));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testDisablingThePluginTakesTheFeatureWithIt(): void
    {
        // The state a third party's IDE is in before their plugin loads: the
        // key does nothing, the tool has no tab, and nothing else is disturbed.
        \putenv('PATH=/nonexistent');
        $project = self::project(['a.php' => "<?php\n// TODO gone\n"], '{"enabledPlugins":[]}');

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, $terminal, Ide $ide): void {
                    $tui->handleInput("\x1b8");
                    $this->pump(0.2); // a genuine negative: prove nothing opened
                    $this->frame($tui);

                    // Model truth, not the screen: the editor is showing a file
                    // whose text literally contains the marker, so scraping for
                    // "TODO" would pass either way.
                    $dock = Services::resolve($ide, BottomDock::class);
                    $this->assertNull($dock->visiblePanel(), 'alt+8 opened no panel');
                    $this->assertSame([], $dock->aliveTabs(), 'and left no tab behind');

                    // And the View menu must not offer a verb nothing answers:
                    // core NAMES ide_todo in its catalog, so a menu item built
                    // from the spec alone would render "Show TODOs", carry the
                    // alt+8 label from the catalog's defaults, and do nothing.
                    /** @var list<\Porta\Workbench\Menu\Menu> $menus */
                    $menus = Probe::get($tui->getById('menu'), 'menus');
                    $view = null;
                    foreach ($menus as $candidate) {
                        if ('View' !== $candidate->title) {
                            continue;
                        }

                        $view = $candidate;
                    }
                    $this->assertNotNull($view, 'the View menu is there to inspect');
                    $labels = \array_map(static fn (object $item): string => $item->label, $view->items);
                    $this->assertContains('Show Problems', $labels, 'and it really is the View menu');
                    $this->assertNotContains('Show TODOs', $labels, 'no dead menu entry for a verb nothing answers');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
