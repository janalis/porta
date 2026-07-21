<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The first-run path, end to end: a genuinely fresh project opens the Welcome
 * screen, working a step configures the project, and the next launch does not
 * show it again.
 *
 * Points PORTA_HOME off the suite-wide `tempest` fixture and PORTA_EMBEDDED_PLUGINS
 * at the repo's bundled dir, so the project is unconfigured exactly as a new
 * user's would be — the same arrangement `HighlightingSettingsTest` uses.
 */
final class WelcomeScreenTest extends TestCase
{
    use RunsIde;

    private ?string $savedHome = null;
    private ?string $savedEmbedded = null;

    protected function setUp(): void
    {
        $home = getenv('PORTA_HOME');
        $embedded = getenv('PORTA_EMBEDDED_PLUGINS');
        $this->savedHome = false === $home ? null : $home;
        $this->savedEmbedded = false === $embedded ? null : $embedded;

        putenv('PORTA_LSP');
        putenv('PORTA_HOME=/nonexistent');
        putenv('PORTA_EMBEDDED_PLUGINS='.\dirname(__DIR__, levels: 2).'/.porta/plugins');
        $this->allowFirstRunWelcome();
    }

    protected function tearDown(): void
    {
        putenv('PORTA_HOME='.($this->savedHome ?? ''));
        putenv('PORTA_EMBEDDED_PLUGINS='.($this->savedEmbedded ?? ''));
    }

    public function testAFreshProjectOpensTheWelcomeScreen(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $terminal = $this->runIde($project, $project->path('a.php'), [
            120 => static function ($tui): void {
                $tui->tick();
            },
        ]);

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Welcome', $screen);
        $this->assertStringContainsString('0 of 2 done', $screen, 'the title counts progress');
        $this->assertStringContainsString('Syntax highlighting', $screen);
        $this->assertStringContainsString('PHP language server', $screen);
    }

    /** Running the first step enables the engine and persists the choice. */
    public function testWorkingAStepConfiguresTheProject(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $this->runIde($project, $project->path('a.php'), [
            120 => function ($tui, $terminal) use ($project): void {
                $tui->handleInput("\r"); // the selected step: enable highlighting
                $tui->tick();

                $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
                $this->assertSame(
                    ['tempest'],
                    \is_array($config) ? $config['enabledPlugins'] ?? null : null,
                    'the choice persisted',
                );
                $this->assertStringContainsString(
                    'Plugin "tempest" enabled',
                    AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                );
            },
        ]);
    }

    /** Esc hands the main area back to the editor. */
    public function testEscapeReturnsToTheEditor(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $terminal = $this->runIde($project, $project->path('a.php'), [
            120 => static function ($tui): void {
                $tui->handleInput("\x1b");
                $tui->tick();
            },
        ]);

        $this->assertStringContainsString('return 1;', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    /** The marker is written at boot, so a second launch goes straight to the editor. */
    public function testASecondLaunchDoesNotShowIt(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $this->runIde($project, $project->path('a.php'), [
            80 => static function ($tui): void {
                $tui->tick();
            },
        ]);

        $this->assertTrue(new OnboardingState($project->dir)->hasSeenWelcome(), 'the first boot recorded it');

        $second = $this->runIde($project, $project->path('a.php'), [
            80 => static function ($tui): void {
                $tui->tick();
            },
        ]);

        $this->assertStringNotContainsString('of 2 done', AnsiUtils::stripAnsiCodes($second->getOutput()));
    }

    /**
     * The mouse must actually reach the pane.
     *
     * These drive real SGR events through MouseRouter rather than calling the
     * widget directly — the earlier unit tests did the latter and passed while
     * the routing did not exist at all.
     */
    public function testClickingASecondStepSelectsItWithoutRunningIt(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $this->runIde($project, $project->path('a.php'), [
            120 => function ($tui, $terminal, $ide) use ($project): void {
                $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                $rect = $renderer->getWidgetRect($tui->getById('welcome'));
                $this->assertNotNull($rect, 'the Welcome pane has a rect to click');

                // Pane rows: 0 title bar, 1-2 first card, 3 spacer, 4-5 second
                // card. SGR coordinates are 1-based, hence the +1.
                $row = $rect->row + 4 + 1;
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + 3, $row));
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + 3, $row));
                $tui->tick();

                $this->assertFileDoesNotExist($project->path('.porta.json'), 'a click must not run a step');

                // Enter now acts on the step the click selected — the language
                // server, not the highlighter the screen opened on.
                $tui->handleInput("\r");
                $tui->tick();

                $config = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true);
                $this->assertSame(
                    ['phpantom'],
                    \is_array($config) ? $config['enabledPlugins'] ?? null : null,
                    'the click moved the selection to the second step',
                );
            },
        ]);
    }

    public function testHoveringAndWheelingReachThePane(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $terminal = $this->runIde($project, $project->path('a.php'), [
            120 => function ($tui, $t, $ide): void {
                $renderer = new \ReflectionProperty($ide, 'renderer')->getValue($ide);
                $rect = $renderer->getWidgetRect($tui->getById('welcome'));

                $step = $rect->row + 1 + 1; // a step row; SGR is 1-based
                $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $rect->col + 3, $step)); // hover
                $tui->handleInput(\sprintf("\x1b[<65;%d;%dM", $rect->col + 3, $step)); // wheel down
                $tui->tick();

                $this->addToAssertionCount(1); // reaching here without an exception is the assertion
            },
        ]);

        $this->assertStringContainsString('Syntax highlighting', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }
}
