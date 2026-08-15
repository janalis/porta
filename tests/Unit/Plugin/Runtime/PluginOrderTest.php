<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigWarning;
use Porta\Plugin\Runtime\PluginManifest;
use Porta\Plugin\Runtime\PluginOrder;

/** Load order, and what cannot load. */
final class PluginOrderTest extends TestCase
{
    /** @param list<string> $requires */
    private function manifest(string $name, array $requires = []): PluginManifest
    {
        return new PluginManifest($name, '/p/plugins/'.$name, requires: $requires);
    }

    /** A warning rendered the way an 'en' Translator would (untranslated: identity substitution), for readable substring assertions. */
    private static function render(ConfigWarning $warning): string
    {
        return strtr($warning->key, $warning->params);
    }

    /**
     * @param array{order: list<PluginManifest>, skipped: array<string, string>} $resolved
     *
     * @return list<string>
     */
    private function names(array $resolved): array
    {
        return array_map(static fn (PluginManifest $manifest): string => $manifest->name, $resolved['order']);
    }

    public function testPluginsWithoutDependenciesKeepDiscoveryOrder(): void
    {
        $resolved = PluginOrder::resolve([$this->manifest('a'), $this->manifest('b'), $this->manifest('c')]);

        $this->assertSame(['a', 'b', 'c'], $this->names($resolved), 'precedence order is preserved when nothing constrains it');
        $this->assertSame([], $resolved['skipped']);
    }

    public function testADependencyLoadsFirstEvenWhenDiscoveredLater(): void
    {
        $resolved = PluginOrder::resolve([$this->manifest('app', ['ui']), $this->manifest('ui')]);

        $this->assertSame(['ui', 'app'], $this->names($resolved));
    }

    public function testTheChainIsTransitive(): void
    {
        $resolved = PluginOrder::resolve([
            $this->manifest('top', ['middle']),
            $this->manifest('middle', ['base']),
            $this->manifest('base'),
        ]);

        $this->assertSame(['base', 'middle', 'top'], $this->names($resolved));
    }

    public function testAMissingDependencyKeepsThePluginOutAndSaysWhy(): void
    {
        $resolved = PluginOrder::resolve([$this->manifest('app', ['absent']), $this->manifest('other')]);

        $this->assertSame(['other'], $this->names($resolved), 'unrelated plugins are unaffected');
        $this->assertArrayHasKey('app', $resolved['skipped']);
        $this->assertStringContainsString('needs "absent"', self::render($resolved['skipped']['app']));
        $this->assertStringContainsString('not installed or is disabled', self::render($resolved['skipped']['app']));
    }

    public function testTheSkipPropagatesToWhateverNeededTheSkippedOne(): void
    {
        $resolved = PluginOrder::resolve([
            $this->manifest('top', ['middle']),
            $this->manifest('middle', ['absent']),
        ]);

        $this->assertSame([], $this->names($resolved));
        $this->assertStringContainsString('needs "absent"', self::render($resolved['skipped']['middle']));
        $this->assertStringContainsString('could not load', self::render($resolved['skipped']['top']));
    }

    public function testACycleIsReportedRatherThanResolved(): void
    {
        // There is no correct order for one, and picking a member to go first
        // would make the bug intermittent.
        $resolved = PluginOrder::resolve([$this->manifest('a', ['b']), $this->manifest('b', ['a'])]);

        $this->assertSame([], $this->names($resolved), 'neither loads');
        $this->assertStringContainsString('dependency cycle', implode(' ', array_map(self::render(...), $resolved['skipped'])));
    }

    public function testASharedDependencyLoadsOnce(): void
    {
        $resolved = PluginOrder::resolve([
            $this->manifest('left', ['base']),
            $this->manifest('right', ['base']),
            $this->manifest('base'),
        ]);

        $this->assertSame(['base', 'left', 'right'], $this->names($resolved));
    }

    public function testADuplicateNameKeepsThePrecedenceCopy(): void
    {
        // Discovery already shadows by directory name (project over global over
        // bundled); the resolver must not undo that.
        $first = $this->manifest('dup');
        $resolved = PluginOrder::resolve([$first, new PluginManifest('dup', '/other/dup')]);

        $this->assertCount(1, $resolved['order']);
        $this->assertSame('/p/plugins/dup', $resolved['order'][0]->directory);
    }
}
