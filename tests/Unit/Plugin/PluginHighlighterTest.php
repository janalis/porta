<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Highlight\CapturingHighlightSink;
use Porta\Highlight\HighlightRequest;
use Porta\Highlight\SyntaxHighlightProvider;
use Porta\Plugin\PluginApi;
use Porta\Plugin\PluginEvents;
use Porta\Plugin\PluginException;
use Porta\Plugin\PluginManager;
use Porta\Plugin\PluginSettings;

/**
 * Plugin-provided highlighting backends: registration, load order, the
 * hot-path fault barrier and live removal.
 */
final class PluginHighlighterTest extends TestCase
{
    private function manager(): PluginManager
    {
        return new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
    }

    private function markup(SyntaxHighlightProvider $provider, string $source): string
    {
        $sink = new CapturingHighlightSink();
        $provider->highlight(new HighlightRequest('/x/a.php', 'php', $source, 1), $sink);

        return (string) $sink->markup;
    }

    private function api(PluginManager $manager, string $name): PluginApi
    {
        return new PluginApi($name, $manager, '/p', new PluginSettings('/p', []));
    }

    public function testRegistersAndExposesABackendInLoadOrder(): void
    {
        $manager = $this->manager();

        $one = $this->api($manager, 'one');
        $one->registerHighlighter('One', ['php'], static function (string $c, string $l, \Closure $d): void {
            $d('ONE:'.$c);
        });
        $manager->commit($one);

        $two = $this->api($manager, 'two');
        $two->registerHighlighter('Two', ['rust'], static function (string $c, string $l, \Closure $d): void {
            $d('TWO:'.$c);
        });
        $manager->commit($two);

        $providers = $manager->highlightProviders();

        $this->assertCount(2, $providers);
        $this->assertSame(['one', 'two'], array_map(static fn ($p): string => $p->id(), $providers));
        $this->assertTrue($providers[0]->supports('php'));
        $this->assertFalse($providers[0]->supports('rust'));
        $this->assertSame('ONE:x', $this->markup($providers[0], 'x'));
    }

    /** The id defaults to the plugin name, and can be overridden for selection. */
    public function testIdDefaultsToThePluginNameAndCanBeOverridden(): void
    {
        $manager = $this->manager();

        $api = $this->api($manager, 'mytool');
        $api->registerHighlighter('Default id', ['php'], static function (string $c, string $l, \Closure $d): void {
            $d($c);
        });
        $api->registerHighlighter(
            'Custom id',
            ['rust'],
            static function (string $c, string $l, \Closure $d): void {
                $d($c);
            },
            id: 'custom',
        );
        $manager->commit($api);

        $this->assertSame(['mytool', 'custom'], array_map(static fn ($p): string => $p->id(), $manager->highlightProviders()));
    }

    /** A closure claim list is re-resolved per query, so a setting edit applies live. */
    public function testClosureLanguageListIsResolvedPerQuery(): void
    {
        $manager = $this->manager();
        $claimed = ['php'];

        $api = $this->api($manager, 'live');
        $api->registerHighlighter(
            'Live',
            static function () use (&$claimed): array {
                return $claimed;
            },
            static function (string $c, string $l, \Closure $d): void {
                $d($c);
            },
        );
        $manager->commit($api);

        $provider = $manager->highlightProviders()[0];
        $this->assertTrue($provider->supports('php'));

        $claimed = ['rust'];
        $this->assertFalse($provider->supports('php'), 're-resolved');
        $this->assertTrue($provider->supports('rust'));
    }

    public function testRejectsAnInvalidId(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'bad');

        $this->expectException(PluginException::class);
        $api->registerHighlighter(
            'Bad',
            ['php'],
            static function (string $c, string $l, \Closure $d): void {
                $d($c);
            },
            id: 'not valid',
        );
    }

    public function testRejectsAnEmptyLanguageList(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'bad');

        $this->expectException(PluginException::class);
        $api->registerHighlighter('Bad', [], static function (string $c, string $l, \Closure $d): void {
            $d($c);
        });
    }

    /**
     * A throwing claim list declines the language rather than propagating —
     * this runs while the user types.
     */
    public function testThrowingClaimListDeclinesAndReportsOnce(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'boom');
        $api->registerHighlighter('Boom', static fn (): array => throw new \RuntimeException('nope'), static function (string $c, string $l, \Closure $d): void {
            $d($c);
        });
        $manager->commit($api);

        $provider = $manager->highlightProviders()[0];

        $this->assertFalse($provider->supports('php'));
        $this->assertFalse($provider->supports('php'), 'still declines on the second ask');

        $warnings = $manager->warnings();
        $this->assertCount(1, $warnings, 'reported once, not once per keystroke');
        $this->assertStringContainsString('nope', $warnings[0]);
    }

    /**
     * A throwing highlight pass reports once and re-throws, so CodeHighlighter
     * turns it into plain text.
     */
    public function testThrowingHighlightReportsOnceAndRethrows(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'crash');
        $api->registerHighlighter('Crash', ['php'], static function (): void {
            throw new \RuntimeException('parse failed');
        });
        $manager->commit($api);

        $provider = $manager->highlightProviders()[0];

        foreach (range(1, end: 3) as $_) {
            try {
                $this->markup($provider, 'x');
                self::fail('expected the throw to propagate');
            } catch (\RuntimeException $error) {
                $this->assertSame('parse failed', $error->getMessage());
            }
        }

        $this->assertCount(1, $manager->warnings(), 'reported once across repeated failures');
    }

    public function testDeactivateRemovesTheBackend(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'gone');
        $api->registerHighlighter('Gone', ['php'], static function (string $c, string $l, \Closure $d): void {
            $d($c);
        });
        $manager->commit($api);

        $this->assertCount(1, $manager->highlightProviders());

        $manager->deactivate('gone', new CommandRegistry());

        $this->assertSame([], $manager->highlightProviders());
    }
}
