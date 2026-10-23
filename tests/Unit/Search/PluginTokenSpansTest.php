<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PluginException;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Search\TokenClassifier;
use Porta\Search\TokenSpanProvider;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Plugin-supplied comment/string classification for project search's
 * token-scope filter.
 */
final class PluginTokenSpansTest extends TestCase
{
    private function manager(): PluginManager
    {
        return new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
    }

    private function api(PluginManager $manager, string $name): PluginApi
    {
        return $manager->api($name, '/p', new PluginSettings('/p', []));
    }

    public function testAPluginClassifierWinsOverTheBuiltIn(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'spans');
        $api->registerTokenSpans(['php'], static fn (): array => [['start' => 2, 'end' => 5]]);
        $manager->commit($api);

        $classifier = new TokenClassifier(null, $manager);

        $this->assertSame([['start' => 2, 'end' => 5]], $classifier->commentStringSpans('<?php // x', 'php'));
    }

    /** Declining (null) hands the language back to the built-in classifier. */
    public function testDecliningFallsBackToTheBuiltInClassifier(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'spans');
        $api->registerTokenSpans(['php'], static fn (): ?array => null);
        $manager->commit($api);

        $classifier = new TokenClassifier(null, $manager);
        $spans = $classifier->commentStringSpans("<?php\n// a comment\n", 'php');

        $this->assertNotSame([], $spans, 'the built-in classified it');
    }

    public function testAClassifierIsSkippedForLanguagesItDoesNotClaim(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'spans');
        $api->registerTokenSpans(['rust'], static fn (): array => [['start' => 0, 'end' => 1]]);
        $manager->commit($api);

        $this->assertNull($manager->commentStringSpans('<?php', 'php'));
    }

    public function testStarClaimsEveryLanguage(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'spans');
        $api->registerTokenSpans(['*'], static fn (): array => [['start' => 1, 'end' => 2]]);
        $manager->commit($api);

        $this->assertSame([['start' => 1, 'end' => 2]], $manager->commentStringSpans('x', 'rust'));
    }

    public function testFirstClaimerInLoadOrderWins(): void
    {
        $manager = $this->manager();

        $one = $this->api($manager, 'one');
        $one->registerTokenSpans(['php'], static fn (): array => [['start' => 1, 'end' => 2]]);
        $manager->commit($one);

        $two = $this->api($manager, 'two');
        $two->registerTokenSpans(['php'], static fn (): array => [['start' => 9, 'end' => 9]]);
        $manager->commit($two);

        $this->assertSame([['start' => 1, 'end' => 2]], $manager->commentStringSpans('x', 'php'));
    }

    /** A faulting classifier must never break search — it just declines. */
    public function testAThrowingClassifierDeclinesAndIsReported(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'boom');
        $api->registerTokenSpans(['php'], static fn (): array => throw new \RuntimeException('classify failed'));
        $manager->commit($api);

        $this->assertNull($manager->commentStringSpans('x', 'php'));
        $this->assertCount(1, $manager->warnings);
    }

    /** A throwing provider seen through TokenClassifier still yields the built-in result. */
    public function testTokenClassifierSurvivesAThrowingProvider(): void
    {
        $throwing = new class implements TokenSpanProvider {
            public function commentStringSpans(string $content, string $language): ?array
            {
                throw new \RuntimeException('provider exploded');
            }
        };

        $classifier = new TokenClassifier(null, $throwing);

        $this->assertNotSame([], $classifier->commentStringSpans("<?php\n// a comment\n", 'php'));
    }

    public function testRejectsAnEmptyLanguageList(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'bad');

        $this->expectException(PluginException::class);
        $api->registerTokenSpans([], static fn (): array => []);
    }

    public function testDeactivateRemovesTheClassifier(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'gone');
        $api->registerTokenSpans(['php'], static fn (): array => [['start' => 0, 'end' => 1]]);
        $manager->commit($api);

        $manager->deactivate('gone', new CommandRegistry());

        $this->assertNull($manager->commentStringSpans('x', 'php'));
    }

    /** With no plugin source at all the built-in is used directly. */
    public function testNoProviderMeansTheBuiltIn(): void
    {
        $classifier = new TokenClassifier();

        $this->assertNotSame([], $classifier->commentStringSpans("<?php\n// a comment\n", 'php'));
    }
}
