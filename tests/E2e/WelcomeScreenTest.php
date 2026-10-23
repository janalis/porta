<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\Region\ModalBox;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

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
        $home = \getenv('PORTA_HOME');
        $embedded = \getenv('PORTA_EMBEDDED_PLUGINS');
        $this->savedHome = false === $home ? null : $home;
        $this->savedEmbedded = false === $embedded ? null : $embedded;

        \putenv('PORTA_LSP');
        \putenv('PORTA_HOME=/nonexistent');
        \putenv('PORTA_EMBEDDED_PLUGINS='.\dirname(__DIR__, levels: 2).'/plugins');
        $this->allowFirstRunWelcome();
    }

    protected function tearDown(): void
    {
        \putenv('PORTA_HOME='.($this->savedHome ?? ''));
        \putenv('PORTA_EMBEDDED_PLUGINS='.($this->savedEmbedded ?? ''));
    }

    /**
     * The open modal's screen box. The widget is never mounted, so there is no
     * rect to hit-test — the controller's region is the mouse-space anchor,
     * reached through the Ide's service-locator boundary like the other
     * reflection seams.
     *
     * The region is only computed while a frame renders, and the modal lays
     * out from the PREVIOUS frame's pane rects — so one forced frame is not
     * always enough. Await it across frames instead: a single frame() before
     * the read is a fixed-gap assert, which is exactly the shape that fails
     * under a loaded coverage run.
     */
    private function modalRegion(object $ide): DropdownRegion
    {
        $controller = Services::resolve($ide, WelcomeModalController::class);
        $tui = Services::resolve($ide, Tui::class);
        $this->await(function () use ($controller, $tui): bool {
            $this->frame($tui);

            return null !== $controller->region();
        }, what: 'the Welcome modal to be placed');

        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region, 'the Welcome modal is open and placed');

        return $region;
    }

    public function testAFreshProjectOpensTheWelcomeScreen(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $terminal = $this->runIde($project, $project->path('a.php'), [
            120 => function (Tui $tui, $terminal): void {
                // The modal lays out from the PREVIOUS frame's pane rects, so
                // it needs two frames: force one, then await the hero.
                $this->frame($tui);
                $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Welcome to Porta'), what: 'the Welcome modal to paint');
            },
        ]);

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Welcome to Porta', $screen, 'the hero greeting');
        $this->assertStringContainsString('0 of 4 done', $screen, 'the title counts the checkable steps');
        $this->assertStringContainsString('Syntax highlighting', $screen);
        $this->assertStringContainsString('PHP language server', $screen);
        $this->assertStringContainsString('Code style fixer', $screen);
        $this->assertStringContainsString('Static analysis', $screen);
        $this->assertStringContainsString('Read the guide', $screen);
    }

    /** Running the first step enables the engine and persists the choice. */
    public function testWorkingAStepConfiguresTheProject(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $this->runIde($project, $project->path('a.php'), [
            120 => function (Tui $tui, $terminal) use ($project): void {
                $this->frame($tui); // the modal lays out from the first frame's rects
                $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Welcome to Porta'), what: 'the Welcome modal to paint');
                $tui->handleInput("\r"); // the selected step: enable highlighting
                $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Plugin "tempest" enabled'), what: 'the enable flash');

                $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                $this->assertSame(
                    [...IdeConfig::DEFAULT_ENABLED_PLUGINS, 'tempest'],
                    \is_array($config) ? $config['enabledPlugins'] ?? null : null,
                    'the choice persisted (the absent-key default materialized first)',
                );
            },
        ]);
    }

    /** A Symfony application gets one more card, and working it enables symfony-lsp. */
    public function testASymfonyProjectOffersTheSymfonyLanguageToolsCard(): void
    {
        $project = new TempProject([
            'a.php' => "<?php\nreturn 1;\n",
            'composer.json' => (string) \json_encode(['require' => ['symfony/framework-bundle' => '^7.0']], \JSON_THROW_ON_ERROR),
        ]);

        $this->runIde($project, $project->path('a.php'), [
            120 => function (Tui $tui, VirtualTerminal $terminal) use ($project): void {
                $this->frame($tui); // the modal lays out from the first frame's rects
                $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Symfony language tools'), what: 'the Symfony card to paint');
                $this->assertStringContainsString('0 of 5 done', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the extra checkable card joins the count');

                $tui->handleInput("\x1b[B"); // highlighting → PHP server
                $tui->handleInput("\x1b[B"); // PHP server → Symfony tools
                $tui->handleInput("\r");
                $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Plugin "symfony-lsp" enabled'), what: 'the enable flash');

                $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                $this->assertSame(
                    [...IdeConfig::DEFAULT_ENABLED_PLUGINS, 'symfony-lsp'],
                    \is_array($config) ? $config['enabledPlugins'] ?? null : null,
                    'the card enables the Symfony server, exactly like Settings → Plugins would',
                );

                // Six cards overflow the 18-row window sized for five; the
                // widget's followHeights() must scroll the selection into
                // view, or the last card would be unreachable below the fold.
                $tui->handleInput("\x1b[B"); // Symfony tools → fixer
                $tui->handleInput("\x1b[B"); // fixer → analysis
                $tui->handleInput("\x1b[B"); // analysis → the guide
                $this->awaitPainted($tui, $terminal, 'Read the guide');
            },
        ]);
    }

    /** Esc hands the main area back to the editor. */
    public function testEscapeReturnsToTheEditor(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $terminal = $this->runIde($project, $project->path('a.php'), [
            120 => function (Tui $tui, $terminal): void {
                $this->frame($tui); // the modal needs its second frame before Esc means anything
                $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Welcome to Porta'), what: 'the Welcome modal to paint');
                $tui->handleInput("\x1b");
                $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'return 1;'), what: 'the editor to reveal');
            },
        ]);

        $this->assertStringContainsString('return 1;', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    /** The marker is written at boot, so a second launch goes straight to the editor. */
    public function testASecondLaunchDoesNotShowIt(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $this->runIde($project, $project->path('a.php'), [
            80 => function (Tui $tui): void {
                $this->frame($tui);
            },
        ]);

        $this->assertTrue(new OnboardingState($project->dir)->hasSeenWelcome(), 'the first boot recorded it');

        $second = $this->runIde($project, $project->path('a.php'), [
            80 => function (Tui $tui): void {
                // Two settled frames: if the modal WERE going to paint, this
                // is when it would — the negative below is then meaningful.
                $this->frame($tui);
                $this->frame($tui);
            },
        ]);

        $this->assertStringNotContainsString('of 4 done', AnsiUtils::stripAnsiCodes($second->getOutput()));
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
            120 => function (Tui $tui, $terminal, object $ide) use ($project): void {
                $this->frame($tui); // the modal lays out from the first frame's rects
                $region = $this->modalRegion($ide);

                // Box rows: 0 frame title bar, 1 pad, 2-4 hero (the content
                // window is ≥ HERO_MIN_ROWS tall), 5-6 first card, 7 spacer,
                // 8-9 second card. SGR coordinates are 1-based, hence the +1;
                // the +5 column clears the rail, padding and content margin.
                $row = $region->top + 8 + 1;
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $region->col + 5, $row));
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $region->col + 5, $row));
                $tui->tick();

                $this->assertFileDoesNotExist($project->path('.porta.json'), 'a click must not run a step');

                // Enter now acts on the step the click selected — the language
                // server, not the highlighter the screen opened on.
                $tui->handleInput("\r");
                $tui->tick();

                $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                $this->assertSame(
                    [...IdeConfig::DEFAULT_ENABLED_PLUGINS, 'phpantom'],
                    \is_array($config) ? $config['enabledPlugins'] ?? null : null,
                    'the click moved the selection to the second step',
                );
            },
        ]);
    }

    /**
     * On a roomy screen each card's button is a box and the card is as tall
     * as it: the second card's last row — the box's bottom border, past its
     * one-line detail — is still that card's to click.
     */
    public function testOnARoomyScreenTheCardsButtonsAreBoxesTheirWholeHeightClickable(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                120 => function (Tui $tui, $terminal, object $ide) use ($project): void {
                    $this->frame($tui);
                    $region = $this->modalRegion($ide);
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertMatchesRegularExpression('/┃ +Enable tempest +┃/u', $screen, 'the selected card\'s button, a heavy box');
                    $this->assertMatchesRegularExpression('/│ +Enable phpantom +│/u', $screen, 'the next one\'s at rest');

                    // Box rows: 0 title bar, 1 pad, 2-4 hero, 5-7 first card, 8
                    // the rule between, 9-11 second card; SGR rows are 1-based.
                    $row = $region->top + 11 + 1;
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $region->col + 5, $row));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $region->col + 5, $row));
                    $tui->tick();
                    $tui->handleInput("\r");
                    $tui->tick();

                    $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                    $this->assertSame(
                        [...IdeConfig::DEFAULT_ENABLED_PLUGINS, 'phpantom'],
                        \is_array($config) ? $config['enabledPlugins'] ?? null : null,
                        'the click on the box\'s last row selected the second step',
                    );
                },
            ],
            rows: 40,
        );
    }

    /**
     * Reopening from the menu resets the transient view state: the widget
     * outlives the view, so without the reset a reopen would resume on
     * whatever step the last visit left selected.
     */
    public function testReopeningFromTheMenuResetsToTheFirstPendingStep(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $this->runIde($project, $project->path('a.php'), [
            120 => function ($tui, $terminal, Ide $ide) use ($project): void {
                $tui->handleInput("\r"); // enable highlighting (step 1)
                $tui->tick();
                $tui->handleInput("\x1b"); // back to the editor
                $tui->tick();

                Services::resolve($ide, CommandRegistry::class)->run('ide_welcome'); // Help → Welcome
                $tui->tick();
                $tui->handleInput("\r");
                $tui->tick();

                $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                $this->assertSame(
                    [...IdeConfig::DEFAULT_ENABLED_PLUGINS, 'tempest', 'phpantom'],
                    \is_array($config) ? $config['enabledPlugins'] ?? null : null,
                    'the reopen landed on the first still-pending step, the language server',
                );
            },
        ]);
    }

    /**
     * The third card is the code style fixer, and Enter enables it for real.
     * The TempProject has no vendor/bin, so this doubles as the deterministic
     * mago-absent branch: the step targets php-cs-fixer on every machine
     * regardless of any globally installed mago ({@see MagoDetector}).
     */
    public function testTheCodeStyleStepEnablesItsPlugin(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $this->runIde($project, $project->path('a.php'), [
            120 => function (Tui $tui, VirtualTerminal $terminal) use ($project): void {
                $tui->handleInput("\x1b[B");
                $tui->handleInput("\x1b[B"); // down to the third card
                $tui->handleInput("\r");
                $tui->tick();

                $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                $this->assertSame(
                    [...IdeConfig::DEFAULT_ENABLED_PLUGINS, 'php-cs-fixer'],
                    \is_array($config) ? $config['enabledPlugins'] ?? null : null,
                    'the choice persisted',
                );
                $this->awaitPainted($tui, $terminal, 'Plugin "php-cs-fixer" enabled');
                $this->assertStringContainsString(
                    'Plugin "php-cs-fixer" enabled',
                    AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                );
            },
        ]);
    }

    /**
     * A project shipping mago gets it as both tool steps' target: one Enter on
     * the fixer card enables the one plugin and completes both steps — the
     * observable proof they share it.
     */
    public function testTheToolStepsTargetMagoWhenTheProjectShipsIt(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);
        \chmod($project->write('vendor/bin/mago', "#!/bin/sh\nexit 0\n"), permissions: 0o755);

        $this->runIde($project, $project->path('a.php'), [
            120 => function (Tui $tui, VirtualTerminal $terminal) use ($project): void {
                $tui->handleInput("\x1b[B");
                $tui->handleInput("\x1b[B"); // down to the third card
                $tui->handleInput("\r");
                $tui->tick();

                $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                $this->assertSame(
                    [...IdeConfig::DEFAULT_ENABLED_PLUGINS, 'mago'],
                    \is_array($config) ? $config['enabledPlugins'] ?? null : null,
                    'one enable, one plugin',
                );
                $this->awaitPainted($tui, $terminal, 'Plugin "mago" enabled');
                $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $this->assertStringContainsString('Plugin "mago" enabled', $screen);
                $this->assertStringContainsString('2 of 4 done', $screen, 'the analysis step completed with the fixer step');
            },
        ]);
    }

    /** The guide step is a link: it closes the view and opens the built-in docs. */
    public function testTheGuideStepOpensTheHelpViewer(): void
    {
        $docs = new TempProject(['README.md' => "# Guides\n\nwelcome prose here\n"]);
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $terminal = $this->runIde(
            $project,
            $project->path('a.php'),
            [
                120 => static function ($tui): void {
                    for ($i = 0; $i < 4; ++$i) {
                        $tui->handleInput("\x1b[B"); // down to the last card
                    }
                    $tui->handleInput("\r");
                    $tui->tick();
                },
            ],
            docsDir: $docs->dir,
        );

        $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('Guides', $screen, 'the help viewer took the main area');
        $this->assertStringContainsString('welcome prose here', $screen, 'with the README rendered');
    }

    /**
     * The modal behaviors, end to end through the real mouse routing: a
     * title-bar drag moves the box, a right-press is swallowed, the app menu
     * still opens (and renders above the box), and the title bar's close
     * button closes for real.
     */
    public function testTheModalDragsAndClosesOnTheCross(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $terminal = $this->runIde($project, $project->path('a.php'), [
            120 => function (Tui $tui, VirtualTerminal $terminal, object $ide): void {
                $this->frame($tui);
                $region = $this->modalRegion($ide);

                // A right-press over the box is swallowed: no context menu opens.
                $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $region->col + 4, $region->top + 5 + 1));
                $tui->tick();
                $this->modalRegion($ide); // awaits the modal: still open

                // Drag by the title bar: press, move left two columns and
                // down two rows, release. On a 24-row terminal the box fills
                // the pane union's height, so the vertical component clamps —
                // the horizontal move is the proof the drag ran.
                // The press is mid-bar, clear of the close button at either end.
                $grab = $region->col + 10;
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $grab + 1, $region->top + 1));
                $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $grab - 1, $region->top + 2 + 1));
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $grab - 1, $region->top + 2 + 1));
                $tui->tick();
                $moved = $this->modalRegion($ide);
                $this->assertSame($region->col - 2, $moved->col, 'the box followed the drag');
                $this->assertSame($region->top, $moved->top, 'the vertical move clamped at the pane union');

                // The app menu opens over the modal and its dropdown renders above it.
                $tui->handleInput("\x1b[<0;2;1M"); // the File title on the menu bar
                $tui->tick();
                $this->awaitPainted($tui, $terminal, 'Save File');
                $this->assertStringContainsString('Save File', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the dropdown rendered above the modal');
                $tui->handleInput("\x1b"); // close the menu again
                $tui->tick();

                // The title bar's close button closes the modal for real: at
                // the box's left on macOS, its right elsewhere — the box knows.
                $box = Probe::get(Services::resolve($ide, WelcomeModalController::class), 'box');
                $this->assertInstanceOf(ModalBox::class, $box);
                $close = $box->closeButton->leads() ? $box->col + 1 : $box->col + $box->width - 2;
                $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $close + 1, $box->startRow + 1));
                $tui->tick();
            },
            240 => function ($tui, $terminal, Ide $ide): void {
                $this->assertFalse(Services::resolve($ide, WelcomeModalController::class)->isOpen(), 'the ✖ closed the modal');
                $this->assertSame('editor', $tui->getFocus()?->getId(), 'focus returned to the editor');
            },
        ]);

        $this->assertStringContainsString('Welcome to Porta', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    /** A click on the workspace beside the modal closes it, as it closes every window. */
    public function testAClickBesideTheModalClosesIt(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                120 => function (Tui $tui, VirtualTerminal $terminal, object $ide): void {
                    $this->frame($tui);
                    $region = $this->modalRegion($ide);
                    $this->assertGreaterThan(1, $region->col, 'room beside the box to click');

                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $region->col - 1, $region->top + 4));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $region->col - 1, $region->top + 4));

                    $this->assertFalse(Services::resolve($ide, WelcomeModalController::class)->isOpen());
                },
            ],
            columns: 120,
        );
    }

    public function testHoveringAndWheelingReachThePane(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $terminal = $this->runIde($project, $project->path('a.php'), [
            120 => function (Tui $tui, $t, object $ide): void {
                $this->frame($tui); // the modal lays out from the first frame's rects
                $region = $this->modalRegion($ide);

                $step = $region->top + 5 + 1; // the first card's title row (after the pad row and hero); SGR is 1-based
                $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $region->col + 5, $step)); // hover
                $tui->handleInput(\sprintf("\x1b[<65;%d;%dM", $region->col + 5, $step)); // wheel down
                $tui->tick();
            },
        ]);

        $this->assertStringContainsString('Syntax highlighting', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }
}
