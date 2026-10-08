<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Config\RecommendedPlugin;
use Porta\Ide;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\ReadsSettingsForm;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The fresh-install path: bundled plugins are opt-in, so a new project has no
 * highlighting engine. Settings → Highlighting has to say so and offer to
 * enable the shipped one, otherwise the only signal is uncoloured code.
 *
 * The suite-wide `tempest` fixture is installed globally via PORTA_HOME, so
 * this opts out of it and points the *bundled* source at the real repo
 * directory — reproducing exactly what a user gets out of the box.
 */
final class HighlightingSettingsTest extends TestCase
{
    use NavigatesSettings;
    use ReadsSettingsForm;
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
        \putenv('PORTA_HOME=/nonexistent'); // no global fixture
        \putenv('PORTA_EMBEDDED_PLUGINS='.\dirname(__DIR__, levels: 2).'/plugins');
    }

    protected function tearDown(): void
    {
        \putenv('PORTA_HOME='.($this->savedHome ?? ''));
        \putenv('PORTA_EMBEDDED_PLUGINS='.($this->savedEmbedded ?? ''));
    }

    public function testAFreshProjectIsUncolouredAndOffersToEnableTheEngine(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $terminal = $this->runIde($project, $project->path('a.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal): void {
                // The assertion below is a pure negative, and an unpainted
                // buffer satisfies it for the wrong reason. Await the SOURCE on
                // screen first: after that, "no keyword colour" means the engine
                // is off rather than that nothing has rendered.
                $this->awaitPainted($tui, $terminal, 'return 1;');
            },
        ]);

        $this->assertStringNotContainsString("\x1b[38;5;176m", $terminal->getOutput(), 'no engine: the buffer is uncoloured');
    }

    public function testTheHighlightingPageOffersTheBundledEngineAndEnablingItWorks(): void
    {
        $project = new TempProject(['a.php' => "<?php\nreturn 1;\n"]);

        $this->runIde($project, $project->path('a.php'), [
            100 => function (Tui $tui, $terminal, Ide $ide): void {
                Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                $tui->tick();
                $this->selectSettingsSection($this->settingsNav($tui), 'highlighting');
                $this->frame($tui); // a bare tick inside the 60fps cap window is not a frame

                $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $this->assertStringContainsString('No highlighting engine is enabled', $screen);
                $this->assertStringContainsString('Enable '.RecommendedPlugin::Highlighter->value, $screen);
            },
            200 => function (Tui $tui, $terminal) use ($project): void {
                // Swapping the content pane detaches the focused widget, which
                // hands focus elsewhere — put it back on the form, then onto
                // the offer row BY NAME. It is no longer the row the selection
                // starts on: every page that writes a file leads with the
                // scope chooser now.
                $form = $this->settingsForm($tui);
                $tui->setFocus($form);
                $this->selectSettingRow($form, 'Enable '.RecommendedPlugin::Highlighter->value);
                $tui->handleInput("\r"); // actuate the button
                $this->frame($tui); // a bare tick inside the 60fps cap window is not a frame

                $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                $this->assertSame(
                    [...IdeConfig::DEFAULT_ENABLED_PLUGINS, RecommendedPlugin::Highlighter->value],
                    \is_array($config) ? $config['enabledPlugins'] ?? null : null,
                    'the choice persisted (the absent-key default materialized first)',
                );

                // The terminal output is cumulative, so "the offer is gone" cannot
                // be asserted from it — that is covered by the controller's unit
                // test. The status flash is unambiguous evidence it took effect.
                $this->assertStringContainsString(
                    'Plugin "'.RecommendedPlugin::Highlighter->value.'" enabled',
                    AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                );
            },
        ]);
    }
}
