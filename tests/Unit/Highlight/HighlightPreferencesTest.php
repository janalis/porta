<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CallableHighlightProvider;
use Porta\Highlight\HighlightPreferences;
use Porta\Highlight\HighlightRegistry;
use Porta\Highlight\SyntaxHighlightProvider;
use Porta\Highlight\SyntaxHighlightProviderSource;

/**
 * The preferences object is a live holder: a Settings-page edit lands in the
 * running instance, and resolution follows with no rebuild anywhere.
 */
final class HighlightPreferencesTest extends TestCase
{
    public function testChangeMovesTheGlobalDefault(): void
    {
        $preferences = new HighlightPreferences();

        $preferences->change(null, 'chroma');

        $this->assertSame('chroma', $preferences->defaultId());
        $this->assertSame('chroma', $preferences->preferredFor('php'));
    }

    public function testClearingTheDefaultReturnsToNoPreference(): void
    {
        $preferences = new HighlightPreferences(default: 'tempest');

        $preferences->change(null, '');

        $this->assertSame('', $preferences->defaultId());
        $this->assertNull($preferences->preferredFor('php'));
    }

    /**
     * Clearing a language must UNSET it, not store '': a stored '' would read
     * as "prefer nothing" and stop the fall-through to the global default.
     */
    public function testClearingALanguageFallsBackToTheDefault(): void
    {
        $preferences = new HighlightPreferences(default: 'tempest');
        $preferences->change('rust', 'chroma');
        $this->assertSame('chroma', $preferences->preferredFor('rust'));

        $preferences->change('rust', '');

        $this->assertSame('tempest', $preferences->preferredFor('rust'));
        $this->assertSame([], $preferences->languages(), 'a cleared language is not "configured"');
    }

    public function testLanguagesReflectsLiveEdits(): void
    {
        $preferences = new HighlightPreferences(languages: ['rust' => 'chroma']);

        $preferences->change('php', 'tempest');

        $this->assertSame(['rust' => 'chroma', 'php' => 'tempest'], $preferences->languages());
    }

    /** The registry holds the same instance, so resolution moves with the edit. */
    public function testRegistryResolutionFollowsAChangeWithoutRebuild(): void
    {
        $preferences = new HighlightPreferences();
        $registry = new HighlightRegistry(
            $this->source([$this->provider('alpha', ['php']), $this->provider('beta', ['php'])]),
            $preferences,
        );
        $this->assertSame('alpha', $registry->providerFor('php')->id());

        $preferences->change('php', 'beta');

        $this->assertSame('beta', $registry->providerFor('php')->id());
    }

    /** @param list<SyntaxHighlightProvider> $providers */
    private function source(array $providers): SyntaxHighlightProviderSource
    {
        return new class($providers) implements SyntaxHighlightProviderSource {
            /** @param list<SyntaxHighlightProvider> $providers */
            public function __construct(
                private readonly array $providers,
            ) {}

            public function highlightProviders(): array
            {
                return $this->providers;
            }
        };
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
}
