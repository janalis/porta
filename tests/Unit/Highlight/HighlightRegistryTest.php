<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CallableHighlightProvider;
use Porta\Highlight\CodeHighlighterFactory;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\HighlightPreferences;
use Porta\Highlight\HighlightRegistry;
use Porta\Highlight\HighlightRequest;
use Porta\Highlight\HighlightSink;
use Porta\Highlight\NoHighlightPlugins;
use Porta\Highlight\SyntaxHighlightProvider;
use Porta\Highlight\SyntaxHighlightProviderSource;

/**
 * Backend selection: configured id, then load order, then the built-in, then plain.
 */
final class HighlightRegistryTest extends TestCase
{
    public function testPreferencesFromConfigAndLookup(): void
    {
        $preferences = HighlightPreferences::fromConfig(['default' => 'tempest', 'languages' => ['rust' => 'chroma']]);

        $this->assertSame('chroma', $preferences->preferredFor('rust'));
        $this->assertSame('tempest', $preferences->preferredFor('php'), 'falls back to the global default');

        $empty = HighlightPreferences::fromConfig([]);
        $this->assertNull($empty->preferredFor('php'), 'no preference is not a preference for ""');
    }

    /**
     * There is no built-in engine in the chain: Tempest is a plugin now, so with
     * nothing enabled a file renders uncoloured rather than falling through to
     * something privileged.
     */
    public function testFallsBackToPlainWhenNoPluginClaimsTheLanguage(): void
    {
        $registry = $this->registry([]);

        $this->assertSame('plain', $registry->providerFor('php')->id);
    }

    /** Nothing claims the empty language, so the plain fallback is the terminal case. */
    public function testFallsBackToPlainWhenNothingClaims(): void
    {
        $registry = $this->registry([]);

        $this->assertSame('plain', $registry->providerFor('')->id);
    }

    public function testFirstPluginInLoadOrderWinsWithoutConfig(): void
    {
        $registry = $this->registry([$this->provider('alpha', ['php']), $this->provider('beta', ['php'])]);

        $this->assertSame('alpha', $registry->providerFor('php')->id);
    }

    public function testConfiguredIdBeatsLoadOrder(): void
    {
        $registry = $this->registry(
            [$this->provider('alpha', ['php']), $this->provider('beta', ['php'])],
            new HighlightPreferences(languages: ['php' => 'beta']),
        );

        $this->assertSame('beta', $registry->providerFor('php')->id);
    }

    public function testStarClaimsEveryLanguage(): void
    {
        $registry = $this->registry([$this->provider('everything', ['*'])]);

        $this->assertSame('everything', $registry->providerFor('rust')->id);
    }

    /** An id nobody provides is a user error: warn by falling through, don't uncolor the file. */
    public function testUnknownConfiguredIdFallsThrough(): void
    {
        $registry = $this->registry([$this->provider('alpha', ['php'])], new HighlightPreferences(default: 'nope'));

        $this->assertSame('alpha', $registry->providerFor('php')->id);
    }

    /** A configured backend that doesn't claim the language must not win it. */
    public function testConfiguredIdThatDoesNotClaimFallsThrough(): void
    {
        $registry = $this->registry(
            [$this->provider('rustonly', ['rust'])],
            new HighlightPreferences(default: 'rustonly'),
        );

        $this->assertSame('plain', $registry->providerFor('php')->id);
    }

    public function testUnavailableProviderDeclines(): void
    {
        $unavailable = new CallableHighlightProvider(
            'missing-binary',
            static fn (): array => ['php'],
            static function (string $c, string $l, \Closure $deliver): void {
                $deliver($c);
            },
            static fn (): bool => false,
        );
        $registry = $this->registry([$unavailable]);

        $this->assertSame('plain', $registry->providerFor('php')->id);
    }

    /** Interrogating a provider must never propagate — a throw means "doesn't claim it". */
    public function testThrowingSupportsIsTreatedAsNotClaiming(): void
    {
        $registry = $this->registry([$this->throwingProvider('boom')]);

        $this->assertSame('plain', $registry->providerFor('php')->id);
    }

    public function testThrowingIdIsSurvivedDuringPreferenceMatching(): void
    {
        $registry = $this->registry([$this->throwingIdProvider()], new HighlightPreferences(default: 'anything'));

        $this->assertSame('plain', $registry->providerFor('php')->id);
    }

    public function testAvailableListsEveryClaimingBackend(): void
    {
        $registry = $this->registry([$this->provider('alpha', ['php'])]);

        $ids = \array_map(static fn (SyntaxHighlightProvider $p): string => $p->id, $registry->available('php'));

        $this->assertSame(['alpha', 'plain'], $ids);
    }

    /**
     * With no language there is nothing to claim, so every real backend
     * declines and only the plain fallback is left.
     */
    public function testAvailableForNoLanguageIsPlainAlone(): void
    {
        $registry = $this->registry([$this->provider('alpha', ['php'])]);

        $ids = \array_map(static fn (SyntaxHighlightProvider $p): string => $p->id, $registry->available(''));

        $this->assertSame(['plain'], $ids);
    }

    public function testFactoryResolvesPerFileAndPinsFragmentsToTheBuiltIn(): void
    {
        $factory = new CodeHighlighterFactory(
            $this->registry([$this->provider('alpha', ['php'])]),
            new DarkTerminalTheme(),
        );

        $php = $factory->forFile('/x/a.php');
        $php->setFile('/x/a.php');
        $this->assertSame('php', $php->getLanguage());

        // Fragments never route to a plugin: diff hunks are non-contiguous lines.
        $this->assertNotNull($factory->forFragments());
    }

    /** Standalone (no container, no plugins) means no backend: plain text. */
    public function testFactoryWorksStandaloneAndRendersPlain(): void
    {
        $highlighter = new CodeHighlighterFactory()->forFile('/x/a.php');
        $highlighter->setFile('/x/a.php');

        $this->assertSame(['<?php', "echo 'x';"], $highlighter->highlight(['<?php', "echo 'x';"], 1));
    }

    public function testNoHighlightPluginsIsEmpty(): void
    {
        $this->assertSame([], new NoHighlightPlugins()->highlightProviders());
    }

    /** @param list<SyntaxHighlightProvider> $providers */
    private function registry(array $providers, ?HighlightPreferences $preferences = null): HighlightRegistry
    {
        $source = new readonly class($providers) implements SyntaxHighlightProviderSource {
            /** @param list<SyntaxHighlightProvider> $providers */
            public function __construct(
                private array $providers,
            ) {}

            public function highlightProviders(): array
            {
                return $this->providers;
            }
        };

        return new HighlightRegistry($source, $preferences ?? new HighlightPreferences());
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

    private function throwingProvider(string $id): SyntaxHighlightProvider
    {
        return new CallableHighlightProvider(
            $id,
            static fn (): array => throw new \RuntimeException('claim list exploded'),
            static function (string $content, string $language, \Closure $deliver): void {
                $deliver($content);
            },
        );
    }

    private function throwingIdProvider(): SyntaxHighlightProvider
    {
        return new class implements SyntaxHighlightProvider {
            public string $id {
                get => throw new \RuntimeException('id exploded');
            }

            public function supports(string $language): bool
            {
                return false;
            }

            public function highlight(HighlightRequest $request, HighlightSink $sink): void
            {
                $sink->deliver($request->documentId, $request->version, $request->text);
            }
        };
    }
}
