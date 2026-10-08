<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Onboarding;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\RecommendedPlugin;
use Porta\Highlight\HighlightRequest;
use Porta\Highlight\HighlightSink;
use Porta\Highlight\NoHighlightPlugins;
use Porta\Highlight\SyntaxHighlightProvider;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\I18n\Translator;
use Porta\Onboarding\MagoDetector;
use Porta\Onboarding\SymfonyDetector;
use Porta\Onboarding\WelcomeStep;
use Porta\Onboarding\WelcomeSteps;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\FakeToolInstallOffers;
use Porta\Tests\Support\TempProject;

/**
 * The setup steps: what they report as done, and that running one delegates to
 * the code that already owns the action rather than reimplementing it.
 */
final class WelcomeStepsTest extends TestCase
{
    private FakePluginLifecycle $lifecycle;
    private CommandRegistry $commands;
    private FakeToolInstallOffers $offers;

    /** The project the detector probes — mago-less by default, so every pre-mago expectation holds unchanged. */
    private TempProject $project;

    /** @var list<string> command ids run through the registry */
    private array $commandsRun = [];

    protected function setUp(): void
    {
        $this->lifecycle = new FakePluginLifecycle();
        $this->offers = new FakeToolInstallOffers();
        $this->project = new TempProject();
        $this->commands = new CommandRegistry();
        $this->commands->add(
            'ide_help',
            function (): void {
                $this->commandsRun[] = 'ide_help';
            },
            label: 'Help',
        );
    }

    private function stepsWithEngine(): WelcomeSteps
    {
        return $this->build($this->loadedEngine());
    }

    private function steps(): WelcomeSteps
    {
        return $this->build(new NoHighlightPlugins());
    }

    private function build(SyntaxHighlightProviderSource $source): WelcomeSteps
    {
        return new WelcomeSteps(new Translator('en'), $source, $this->lifecycle, $this->commands, new MagoDetector($this->project->path()), new SymfonyDetector($this->project->path()), $this->offers);
    }

    /** Make the project a Symfony application, adding the symfony-lsp card. */
    private function withSymfony(): void
    {
        $this->project->write('composer.json', (string) \json_encode(['require' => ['symfony/framework-bundle' => '^7.0']], \JSON_THROW_ON_ERROR));
    }

    /** Ship an executable mago in the project, flipping both tool steps' target. */
    private function withMago(): void
    {
        \chmod($this->project->write('vendor/bin/mago', "#!/bin/sh\nexit 0\n"), permissions: 0o755);
    }

    /** A source with one real backend, i.e. highlighting is configured. */
    private function loadedEngine(): SyntaxHighlightProviderSource
    {
        return new class implements SyntaxHighlightProviderSource {
            public function highlightProviders(): array
            {
                return [
                    new class implements SyntaxHighlightProvider {
                        public string $id {
                            get => 'stub';
                        }

                        public function supports(string $language): bool
                        {
                            return true;
                        }

                        public function highlight(HighlightRequest $request, HighlightSink $sink): void
                        {
                            $sink->deliver($request->documentId, $request->version, $request->text);
                        }
                    },
                ];
            }
        };
    }

    public function testOffersTheSetupStepsInOrder(): void
    {
        $titles = \array_map(static fn (WelcomeStep $step): string => $step->title, $this->steps()->all());

        $this->assertSame(['Syntax highlighting', 'PHP language server', 'Code style fixer', 'Static analysis', 'Read the guide'], $titles);
    }

    public function testASymfonyProjectGetsTheSymfonyLanguageToolsCardAfterThePhpServer(): void
    {
        $this->withSymfony();

        $steps = $this->steps()->all();
        $titles = \array_map(static fn (WelcomeStep $step): string => $step->title, $steps);

        $this->assertSame(['Syntax highlighting', 'PHP language server', 'Symfony language tools', 'Code style fixer', 'Static analysis', 'Read the guide'], $titles);
        $this->assertSame('Enable symfony-lsp', $steps[2]->actionLabel);
        $this->assertFalse($steps[2]->isDone());

        $steps[2]->run();
        $this->assertSame([[RecommendedPlugin::SymfonyLanguageServer->value, true]], $this->lifecycle->toggled, 'the same enable call Settings → Plugins makes');

        $this->lifecycle->names[] = RecommendedPlugin::SymfonyLanguageServer->value;
        $this->assertTrue($this->steps()->all()[2]->isDone(), 'no declared tool to wait for, so enabling IS the whole step');
    }

    /**
     * Enabled is not done while the server binary is missing.
     *
     * symfony-lsp's server ships only as a GitHub release, so the card used to
     * tick itself the moment the plugin switched on — leaving the user with a
     * finished checklist and a status bar reading `LSP:none`. Every other card
     * is done at enable because every other plugin brings its own code.
     */
    public function testTheSymfonyCardWaitsForTheServerBinaryNotJustThePlugin(): void
    {
        $this->withSymfony();
        $this->lifecycle->names[] = RecommendedPlugin::SymfonyLanguageServer->value;
        $this->offers->declare(RecommendedPlugin::SymfonyLanguageServer->value, 'symfony-lsp', available: false);

        $this->assertFalse($this->steps()->all()[2]->isDone(), 'enabled, but there is no server to talk to');

        $this->offers->declare(RecommendedPlugin::SymfonyLanguageServer->value, 'symfony-lsp', available: true);
        $this->assertTrue($this->steps()->all()[2]->isDone());
    }

    /** Pressing the card enables the plugin AND downloads the server it needs. */
    public function testRunningTheSymfonyCardAlsoInstallsTheServer(): void
    {
        $this->withSymfony();
        $this->offers->declare(RecommendedPlugin::SymfonyLanguageServer->value, 'symfony-lsp', available: false, package: 'install');
        $this->commands->add(
            'symfony-lsp:install',
            function (): void {
                $this->commandsRun[] = 'symfony-lsp:install';
            },
            label: 'Symfony LSP: Install Server',
        );

        $this->steps()->all()[2]->run();

        $this->assertSame([[RecommendedPlugin::SymfonyLanguageServer->value, true]], $this->lifecycle->toggled);
        $this->assertSame(['symfony-lsp:install'], $this->commandsRun, 'the plugin\'s own installer, not a package manager');
    }

    /** A server already there is not downloaded again by an onboarding card. */
    public function testRunningTheSymfonyCardDoesNotReinstallAServerThatIsThere(): void
    {
        $this->withSymfony();
        $this->offers->declare(RecommendedPlugin::SymfonyLanguageServer->value, 'symfony-lsp', available: true);
        $this->commands->add(
            'symfony-lsp:install',
            function (): void {
                $this->commandsRun[] = 'symfony-lsp:install';
            },
            label: 'Symfony LSP: Install Server',
        );

        $this->steps()->all()[2]->run();

        $this->assertSame([], $this->commandsRun);
    }

    /**
     * A plugin whose tool comes from a package manager keeps the enable-only
     * behaviour: its install lives on the settings page, where the manager can
     * be named, and a welcome card must not run `composer require` behind a
     * button that says "Enable".
     */
    public function testAPackageManagerOfferIsNotRunFromTheCard(): void
    {
        $this->withSymfony();
        $this->offers->declare(RecommendedPlugin::SymfonyLanguageServer->value, 'symfony-lsp', available: false, manager: 'composer', package: 'acme/thing');
        $this->commands->add(
            'symfony-lsp:install',
            function (): void {
                $this->commandsRun[] = 'symfony-lsp:install';
            },
            label: 'Symfony LSP: Install Server',
        );

        $this->steps()->all()[2]->run();

        $this->assertSame([[RecommendedPlugin::SymfonyLanguageServer->value, true]], $this->lifecycle->toggled, 'it still enables');
        $this->assertSame([], $this->commandsRun);
    }

    /** With no backend loaded the highlighting step is the thing to do. */
    public function testHighlightingIsPendingWhenNoEngineIsLoaded(): void
    {
        $this->assertFalse($this->steps()->all()[0]->isDone());
    }

    public function testHighlightingIsDoneOnceAnEngineIsLoaded(): void
    {
        $this->assertTrue($this->stepsWithEngine()->all()[0]->isDone());
    }

    public function testRunningTheHighlightingStepEnablesTheBundledEngine(): void
    {
        $this->steps()->all()[0]->run();

        $this->assertSame([[RecommendedPlugin::Highlighter->value, true]], $this->lifecycle->toggled);
    }

    /** The offer names the actual plugin it would enable, not a generic label. */
    public function testTheHighlightingAndLanguageServerStepsNameTheirTool(): void
    {
        $steps = $this->steps()->all();

        $this->assertSame('Enable tempest', $steps[0]->actionLabel);
        $this->assertSame('Enable phpantom', $steps[1]->actionLabel);
    }

    /**
     * Installed but not opted into is still pending — bundled plugins are opt-in.
     * The three plugin steps share the predicate, so all three are asserted.
     */
    public function testThePluginStepsArePendingWhenTheirPluginsAreNotListed(): void
    {
        $steps = $this->steps()->all();

        $this->assertFalse($steps[1]->isDone());
        $this->assertFalse($steps[2]->isDone());
        $this->assertFalse($steps[3]->isDone());
    }

    public function testThePluginStepsAreDoneWhenTheirPluginsAreListedAndNotDisabled(): void
    {
        $this->lifecycle->names = [RecommendedPlugin::LanguageServer->value, RecommendedPlugin::Formatter->value, RecommendedPlugin::Analyzer->value];
        $steps = $this->steps()->all();

        $this->assertTrue($steps[1]->isDone());
        $this->assertTrue($steps[2]->isDone());
        $this->assertTrue($steps[3]->isDone());
    }

    /** A listed-but-disabled plugin is not serving anything, so the step stands. */
    public function testAPluginStepIsPendingWhenItsPluginIsDisabled(): void
    {
        $this->lifecycle->names = [RecommendedPlugin::LanguageServer->value];
        $this->lifecycle->disabledNames = [RecommendedPlugin::LanguageServer->value];

        $this->assertFalse($this->steps()->all()[1]->isDone());
    }

    public function testRunningTheLanguageServerStepEnablesItsPlugin(): void
    {
        $this->steps()->all()[1]->run();

        $this->assertSame([[RecommendedPlugin::LanguageServer->value, true]], $this->lifecycle->toggled);
    }

    public function testRunningTheCodeStyleStepEnablesItsPlugin(): void
    {
        $this->steps()->all()[2]->run();

        $this->assertSame([[RecommendedPlugin::Formatter->value, true]], $this->lifecycle->toggled);
    }

    public function testRunningTheStaticAnalysisStepEnablesItsPlugin(): void
    {
        $this->steps()->all()[3]->run();

        $this->assertSame([[RecommendedPlugin::Analyzer->value, true]], $this->lifecycle->toggled);
    }

    /** The details name the tool a step will enable, so the offer is explicit. */
    public function testTheToolStepsNameTheirDefaultToolsWithoutMago(): void
    {
        $steps = $this->steps()->all();

        $this->assertStringContainsString(RecommendedPlugin::Formatter->value, $steps[2]->detail);
        $this->assertStringContainsString(RecommendedPlugin::Analyzer->value, $steps[3]->detail);
    }

    /** A project shipping mago gets it as both the fixer and the analyzer. */
    public function testBothToolStepsTargetMagoWhenTheProjectShipsIt(): void
    {
        $this->withMago();
        $steps = $this->steps()->all();

        $this->assertSame('Enable mago', $steps[2]->actionLabel);
        $this->assertSame('Enable mago', $steps[3]->actionLabel);
        $this->assertStringContainsString(RecommendedPlugin::BothTools->value, $steps[2]->detail);
        $this->assertStringContainsString(RecommendedPlugin::BothTools->value, $steps[3]->detail);
    }

    public function testRunningEitherToolStepWithMagoEnablesTheMagoPlugin(): void
    {
        $this->withMago();

        $this->steps()->all()[2]->run();
        $this->steps()->all()[3]->run();

        $this->assertSame(
            [
                [RecommendedPlugin::BothTools->value, true],
                [RecommendedPlugin::BothTools->value, true],
            ],
            $this->lifecycle->toggled,
        );
    }

    /** One mago enable satisfies both tool steps — they share the target. */
    public function testEnablingMagoCompletesBothToolSteps(): void
    {
        $this->withMago();
        $this->lifecycle->names = [RecommendedPlugin::BothTools->value];
        $steps = $this->steps()->all();

        $this->assertTrue($steps[2]->isDone());
        $this->assertTrue($steps[3]->isDone());
    }

    /**
     * The steps *target* mago: an already-enabled php-cs-fixer or phpstan does
     * not satisfy them, because the offer is "enable the recommended tool".
     */
    public function testOtherToolsDoNotSatisfyMagoTargetedSteps(): void
    {
        $this->withMago();
        $this->lifecycle->names = [RecommendedPlugin::Formatter->value, RecommendedPlugin::Analyzer->value];
        $steps = $this->steps()->all();

        $this->assertFalse($steps[2]->isDone());
        $this->assertFalse($steps[3]->isDone());
    }

    /** The step list itself is stable — mago changes the target, not the screen. */
    public function testTheStepTitlesAreTheSameWithMago(): void
    {
        $this->withMago();
        $titles = \array_map(static fn (WelcomeStep $step): string => $step->title, $this->steps()->all());

        $this->assertSame(['Syntax highlighting', 'PHP language server', 'Code style fixer', 'Static analysis', 'Read the guide'], $titles);
    }

    /** The guide is a link step: no done state, never counted, always runnable. */
    public function testTheGuideStepIsALinkAndNeverReadsAsDone(): void
    {
        $guide = $this->steps()->all()[4];

        $this->assertFalse($guide->isCheckable());
        $this->assertFalse($guide->isDone());
    }

    /** Opening the guide is the ide_help command, not a second implementation. */
    public function testRunningTheGuideStepRunsTheHelpCommand(): void
    {
        $this->steps()->all()[4]->run();

        $this->assertSame(['ide_help'], $this->commandsRun);
        $this->assertSame([], $this->lifecycle->toggled, 'nothing was enabled');
    }
}
