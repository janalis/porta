<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Onboarding;

use PHPUnit\Framework\TestCase;
use Porta\Config\SettingsHighlightController;
use Porta\Highlight\NoHighlightPlugins;
use Porta\Highlight\SyntaxHighlightProvider;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\I18n\Translator;
use Porta\Onboarding\WelcomeSteps;
use Porta\Tests\Support\FakePluginLifecycle;

/**
 * The setup steps: what they report as done, and that running one delegates to
 * the code that already owns the action rather than reimplementing it.
 */
final class WelcomeStepsTest extends TestCase
{
    private FakePluginLifecycle $lifecycle;

    protected function setUp(): void
    {
        $this->lifecycle = new FakePluginLifecycle();
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
        return new WelcomeSteps(new Translator('en'), $source, $this->lifecycle);
    }

    /** A source with one real backend, i.e. highlighting is configured. */
    private function loadedEngine(): SyntaxHighlightProviderSource
    {
        return new class implements SyntaxHighlightProviderSource {
            public function highlightProviders(): array
            {
                return [
                    new class implements SyntaxHighlightProvider {
                        public function id(): string
                        {
                            return 'stub';
                        }

                        public function supports(string $language): bool
                        {
                            return true;
                        }

                        public function highlight(\Porta\Highlight\HighlightRequest $request, \Porta\Highlight\HighlightSink $sink): void
                        {
                            $sink->deliver($request->documentId, $request->version, $request->text);
                        }
                    },
                ];
            }
        };
    }

    public function testOffersHighlightingAndALanguageServer(): void
    {
        $titles = array_map(static fn ($step): string => $step->title, $this->steps()->all());

        $this->assertSame(['Syntax highlighting', 'PHP language server'], $titles);
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

        $this->assertSame([[SettingsHighlightController::BUNDLED_ENGINE, true]], $this->lifecycle->toggled);
    }

    /** Installed but not opted into is still pending — bundled plugins are opt-in. */
    public function testTheLanguageServerIsPendingWhenTheBundledPluginIsNotListed(): void
    {
        $this->assertFalse($this->steps()->all()[1]->isDone());
    }

    public function testTheLanguageServerIsDoneWhenItsPluginIsListedAndNotDisabled(): void
    {
        $this->lifecycle->names = [WelcomeSteps::PHP_LANGUAGE_SERVER];

        $this->assertTrue($this->steps()->all()[1]->isDone());
    }

    /** A listed-but-disabled plugin is not serving anything, so the step stands. */
    public function testTheLanguageServerIsPendingWhenItsPluginIsDisabled(): void
    {
        $this->lifecycle->names = [WelcomeSteps::PHP_LANGUAGE_SERVER];
        $this->lifecycle->disabledNames = [WelcomeSteps::PHP_LANGUAGE_SERVER];

        $this->assertFalse($this->steps()->all()[1]->isDone());
    }

    public function testRunningTheLanguageServerStepEnablesItsPlugin(): void
    {
        $this->steps()->all()[1]->run();

        $this->assertSame([[WelcomeSteps::PHP_LANGUAGE_SERVER, true]], $this->lifecycle->toggled);
    }
}
