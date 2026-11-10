<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Plugin\Api\Capability;
use Porta\Plugin\Api\PluginException;
use Porta\Plugin\Runtime\PluginCapabilities;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** A feature core uses when a plugin lends it one: the first plugin standing is the one. */
final class PluginCapabilitiesTest extends TestCase
{
    public function testTheFirstPluginStandingProvidesAndTheNextStandsIn(): void
    {
        $capabilities = new PluginCapabilities();
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings, capabilities: $capabilities);
        $first = static function (): void {};
        $second = static function (): void {};

        $api = $manager->api('first', '/p', $settings);
        $api->provide(Capability::SvgRasterizer, $first);
        $this->assertNull($capabilities->first(Capability::SvgRasterizer), 'nothing counts before the plugin is committed');
        $manager->commit($api);
        $this->assertSame($first, $capabilities->first(Capability::SvgRasterizer));

        $next = $manager->api('second', '/p', $settings);
        $next->provide(Capability::SvgRasterizer, $second);
        $manager->commit($next);
        $this->assertSame($first, $capabilities->first(Capability::SvgRasterizer), 'load order: the first keeps it');

        $manager->deactivate('first', new CommandRegistry());
        $this->assertSame($second, $capabilities->first(Capability::SvgRasterizer));
        $manager->deactivate('second', new CommandRegistry());
        $this->assertNull($capabilities->first(Capability::SvgRasterizer));
    }

    public function testEachChangeToWhatIsProvidedIsCounted(): void
    {
        $capabilities = new PluginCapabilities();
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings, capabilities: $capabilities);
        $quiet = $manager->api('quiet', '/p', $settings);
        $manager->commit($quiet);
        $this->assertSame(0, $capabilities->changes(), 'a plugin that provides nothing changes nothing');

        $api = $manager->api('lender', '/p', $settings);
        $api->provide(Capability::SvgRasterizer, static function (): void {});
        $manager->commit($api);
        $this->assertSame(1, $capabilities->changes());
        $manager->deactivate('quiet', new CommandRegistry());
        $this->assertSame(1, $capabilities->changes());
        $manager->deactivate('lender', new CommandRegistry());
        $this->assertSame(2, $capabilities->changes());
    }

    public function testACapabilityIsProvidedOnlyDuringActivate(): void
    {
        $settings = new PluginSettings('/p', []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $api = $manager->api('late', '/p', $settings);
        $manager->commit($api);

        $this->expectException(PluginException::class);
        $this->expectExceptionMessageIsOrContains('Capability "svg-rasterizer" must be provided during activate()');
        $api->provide(Capability::SvgRasterizer, static function (): void {});
    }
}
