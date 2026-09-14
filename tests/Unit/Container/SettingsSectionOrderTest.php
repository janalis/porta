<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use Porta\Settings\Section\GeneralSection;
use Porta\Settings\Section\HighlightingSection;
use Porta\Settings\Section\InterfaceSection;
use Porta\Settings\Section\LanguageSection;
use Porta\Settings\Section\PluginsSection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * SettingsViewController takes the first `porta.settings_section`-tagged section
 * whose handles() claims the active nav id, walking them in #[AsTaggedItem]
 * priority order (higher first). That order is contractual because
 * {@see GeneralSection} claims EVERY id as the fallback: promote it above any
 * other section and that section's page becomes unreachable — silently, since
 * the general form still renders.
 *
 * Pins the tagged set and the order, mirroring CommandProviderOrderTest.
 */
final class SettingsSectionOrderTest extends ContainerTestCase
{
    public function testSettingsSectionTagIsExactlyTheExpectedSectionsInContractualOrder(): void
    {
        $container = $this->buildContainer();

        $priorities = [];
        foreach (\array_keys($container->findTaggedServiceIds('porta.settings_section')) as $id) {
            $attributes = new \ReflectionClass($id)->getAttributes(AsTaggedItem::class);
            $this->assertCount(1, $attributes, $id.' must declare its match order with #[AsTaggedItem]');
            $priorities[$id] = (int) $attributes[0]->newInstance()->priority;
        }
        \arsort($priorities);
        $actual = \array_keys($priorities);

        $expected = [
            InterfaceSection::class, // 600
            HighlightingSection::class, // 500
            PluginsSection::class, // 400 — claims both 'plugins' and 'plugin:<name>'
            LanguageSection::class, // 200
            GeneralSection::class, // 100 — the catch-all; MUST stay last
        ];

        $this->assertSame($expected, $actual, 'every settings section must carry #[AsTaggedItem] with its contractual priority');
    }

    public function testOnlyTheGeneralSectionClaimsAnUnknownNavId(): void
    {
        $container = $this->buildContainer();

        $claimers = [];
        foreach (\array_keys($container->findTaggedServiceIds('porta.settings_section')) as $id) {
            // The sections are private services, and handles() reads no
            // constructor state — so ask the class, not the container.
            /** @var \Porta\Settings\SettingsSection $section */
            $section = new \ReflectionClass($id)->newInstanceWithoutConstructor();
            if ($section->handles('no-such-section')) {
                $claimers[] = $id;
            }
        }

        $this->assertSame([GeneralSection::class], $claimers, 'exactly one catch-all section, or the fallback is ambiguous');
    }
}
