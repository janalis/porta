<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Config\ActionRegistry;
use Porta\Config\RebindableActionSource;

/** Core's action table composed with the ones plugins register. */
final class ActionRegistryTest extends TestCase
{
    private function registry(array $plugin): ActionRegistry
    {
        return new ActionRegistry(new readonly class($plugin) implements RebindableActionSource {
            /** @param array<string, string> $actions */
            public function __construct(
                private array $actions,
            ) {}

            public function rebindableActions(): array
            {
                return $this->actions;
            }
        });
    }

    public function testItListsCoresActionsThenThePluginsInLoadOrder(): void
    {
        $labels = $this->registry(['demo_zoom' => 'Demo: Zoom', 'demo_pan' => 'Demo: Pan'])->labels();

        $this->assertSame('Save File', $labels['save'] ?? null, "core's rows come through with their labels");
        $this->assertSame('Demo: Zoom', $labels['demo_zoom']);
        $this->assertCount(\count(ActionCatalog::ACTIONS) + 2, $labels);

        // The order IS the keymap page's order: core, then plugins in load
        // order — so enabling a plugin appends rows rather than reshuffling the
        // page under the user.
        $ids = \array_keys($labels);
        $this->assertSame(['demo_zoom', 'demo_pan'], \array_slice($ids, -2));
        $this->assertSame(\array_keys(ActionCatalog::ACTIONS), \array_slice($ids, offset: 0, length: \count(ActionCatalog::ACTIONS)));
    }

    public function testMembershipCoversBothHalves(): void
    {
        $registry = $this->registry(['demo_zoom' => 'Demo: Zoom']);

        $this->assertTrue($registry->has('save'), "core's");
        $this->assertTrue($registry->has('demo_zoom'), "the plugin's");
        $this->assertFalse($registry->has('nothing_declares_this'));
    }

    public function testAnUndeclaredIdLabelsAsItself(): void
    {
        // A keymap row is never rendered for one (`labels()` is what the page
        // iterates), so this is the defensive tail — better a bare id than an
        // empty cell if a caller ever asks about an id it did not get from here.
        $this->assertSame('mystery', $this->registry([])->label('mystery'));
        $this->assertSame('Save File', $this->registry([])->label('save'));
    }

    public function testAPluginMayNotShadowACoreAction(): void
    {
        // Registration already refuses a core id; this is the second lock, for
        // an id that arrives some other way. Core's label and core's position
        // both survive — a plugin cannot relabel "Save File" nor push the
        // page's first rows around.
        $labels = $this->registry(['save' => 'Demo: Not Save', 'demo_zoom' => 'Demo: Zoom'])->labels();

        $this->assertSame('Save File', $labels['save']);
        $this->assertSame(\array_keys(ActionCatalog::ACTIONS), \array_slice(\array_keys($labels), offset: 0, length: \count(ActionCatalog::ACTIONS)));
        $this->assertSame('Demo: Zoom', $labels['demo_zoom'], 'a non-colliding row still lands');
    }
}
