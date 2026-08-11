<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Config\PortaJsonWriter;
use Porta\Config\RecommendedPlugins;
use Porta\Highlight\CallableHighlightProvider;
use Porta\Highlight\HighlightPreferences;
use Porta\Highlight\HighlightRegistry;
use Porta\Highlight\SyntaxHighlightProvider;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\I18n\Translator;
use Porta\Settings\SettingsHighlightController;
use Porta\Settings\SettingsRowKind;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\TempProject;

/**
 * Settings → Highlighting: derived rows, and persistence into the top-level
 * `highlight` block.
 */
final class SettingsHighlightControllerTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->lifecycle = new FakePluginLifecycle();
    }

    private FakePluginLifecycle $lifecycle;

    /** @param list<SyntaxHighlightProvider> $providers */
    private function controller(array $providers = [], string $config = ''): SettingsHighlightController
    {
        if ('' !== $config) {
            $this->project->write('.porta.json', $config);
        }

        $source = new class($providers) implements SyntaxHighlightProviderSource {
            /** @param list<SyntaxHighlightProvider> $providers */
            public function __construct(
                private readonly array $providers,
            ) {}

            public function highlightProviders(): array
            {
                return $this->providers;
            }
        };

        return new SettingsHighlightController(
            new HighlightRegistry($source, new HighlightPreferences()),
            new Translator('en'),
            $this->project->dir,
            IdeConfig::load($this->project->dir),
            new PortaJsonWriter(),
            $this->lifecycle,
        );
    }

    /** @param list<string> $languages */
    private function provider(string $id, array $languages): CallableHighlightProvider
    {
        return new CallableHighlightProvider(
            $id,
            static fn (): array => $languages,
            static function (string $content, string $language, \Closure $deliver): void {
                $deliver($content);
            },
        );
    }

    /** With no backends there is nothing to contest — only the global default row. */
    public function testWithNoBackendsOnlyTheDefaultRowIsShown(): void
    {
        $rows = $this->controller()->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(SettingsHighlightController::DEFAULT_KEY, $rows[0]->key);
        $this->assertSame(SettingsRowKind::Choice, $rows[0]->kind);
    }

    /**
     * A fresh project has no engine — bundled plugins are opt-in — so the page
     * would otherwise offer only "no colouring" with no hint that one ships.
     */
    public function testOffersToEnableTheBundledEngineWhenNothingHighlights(): void
    {
        $this->lifecycle->names = [RecommendedPlugins::HIGHLIGHTER];

        $rows = $this->controller()->rows();

        $this->assertSame(SettingsHighlightController::ENABLE_KEY, $rows[0]->key);
        $this->assertSame(SettingsRowKind::Action, $rows[0]->kind);
    }

    public function testActuatingTheOfferEnablesThePluginLive(): void
    {
        $this->lifecycle->names = [RecommendedPlugins::HIGHLIGHTER];

        $this->controller()->change(SettingsHighlightController::ENABLE_KEY, '');

        $this->assertSame([[RecommendedPlugins::HIGHLIGHTER, true]], $this->lifecycle->toggled);
    }

    /** Once an engine is loaded the offer is gone — there is nothing to enable. */
    public function testTheOfferDisappearsOnceAnEngineIsAvailable(): void
    {
        $this->lifecycle->names = [RecommendedPlugins::HIGHLIGHTER];

        $rows = $this->controller([$this->provider('tempest', ['*'])])->rows();

        $this->assertNotSame(SettingsHighlightController::ENABLE_KEY, $rows[0]->key);
    }

    /** Nothing to offer when the bundled engine isn't installed at all. */
    public function testNoOfferWhenTheBundledEngineIsNotInstalled(): void
    {
        $rows = $this->controller()->rows();

        $this->assertNotSame(SettingsHighlightController::ENABLE_KEY, $rows[0]->key);
    }

    /**
     * A language only gets a row when there is a real choice: the plain
     * fallback plus one engine is not a choice.
     */
    public function testOnlyContestedLanguagesGetTheirOwnRow(): void
    {
        $rows = $this->controller([
            $this->provider('tempest', ['*']),
            $this->provider('chroma', ['rust', 'go']),
        ])->rows();

        $keys = array_column($rows, 'key');

        $this->assertSame([SettingsHighlightController::DEFAULT_KEY, 'lang:go', 'lang:rust'], $keys);
        $this->assertSame(['', 'tempest', 'chroma', 'plain'], $rows[1]->options, 'plugin load order, then the fallback');
    }

    /** One engine claiming everything means no per-language rows at all. */
    public function testASingleBackendContestsNothing(): void
    {
        $rows = $this->controller([$this->provider('tempest', ['*'])])->rows();

        $this->assertCount(1, $rows);
    }

    public function testTheDefaultRowOffersEveryBackendPlusAuto(): void
    {
        $rows = $this->controller([
            $this->provider('tempest', ['*']),
            $this->provider('chroma', ['rust']),
        ])->rows();

        $this->assertSame(['', 'tempest', 'chroma', 'plain'], $rows[0]->options);
        $this->assertSame('auto (first available)', $rows[0]->optionLabels[''] ?? null);
    }

    public function testChangingTheDefaultPersistsToTheHighlightBlock(): void
    {
        $controller = $this->controller([$this->provider('chroma', ['rust'])]);

        $controller->change(SettingsHighlightController::DEFAULT_KEY, 'chroma');

        $this->assertSame(['default' => 'chroma'], $this->written()['highlight'] ?? null);
    }

    public function testChangingALanguagePersistsUnderLanguages(): void
    {
        $controller = $this->controller([$this->provider('chroma', ['rust'])]);

        $controller->change('lang:rust', 'chroma');

        $this->assertSame(['languages' => ['rust' => 'chroma']], $this->written()['highlight'] ?? null);
    }

    /** Clearing removes the entry rather than storing an empty backend name. */
    public function testClearingAnOverrideRemovesIt(): void
    {
        $controller = $this->controller(
            [$this->provider('chroma', ['rust'])],
            '{"highlight":{"default":"chroma","languages":{"rust":"chroma"}}}',
        );

        $controller->change('lang:rust', '');
        $controller->change(SettingsHighlightController::DEFAULT_KEY, '');

        $this->assertArrayNotHasKey('highlight', $this->written());
    }

    /** A configured language keeps its row even once nothing contests it. */
    public function testAConfiguredLanguageKeepsItsRowSoItCanBeCleared(): void
    {
        $rows = $this->controller([], '{"highlight":{"languages":{"rust":"chroma"}}}')->rows();

        $keys = array_column($rows, 'key');
        $this->assertContains('lang:rust', $keys);
        $this->assertSame('chroma', $rows[1]->value);
    }

    /** @return array<string, mixed> */
    private function written(): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents($this->project->path('.porta.json')), associative: true);
    }
}
