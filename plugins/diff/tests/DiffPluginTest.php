<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigWarning;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The plugin git and review need for their diffs — loaded through the real
 * loader, so the requirement is the one the manifests declare.
 */
final class DiffPluginTest extends TestCase
{
    /** @param list<string> $enabled */
    private static function load(array $enabled): PluginManager
    {
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $root = \dirname(__DIR__, levels: 3);
        new PluginLoader($root.'/tests', null, new PluginSettings($root.'/tests', []), $root.'/plugins', enabled: $enabled)->loadInto($manager);

        return $manager;
    }

    public function testItLoadsAndGitAndReviewLoadOnIt(): void
    {
        $manager = self::load(['diff', 'git', 'review']);

        $this->assertTrue($manager->hasPlugin('diff'));
        $this->assertTrue($manager->hasPlugin('git'));
        $this->assertTrue($manager->hasPlugin('review'));
        $this->assertSame([], $manager->warnings);
    }

    public function testAListNamingGitButNotDiffSkipsGitAndSaysWhy(): void
    {
        // An explicit `enabledPlugins` written before diff moved out of git.
        $manager = self::load(['git']);

        $this->assertFalse($manager->hasPlugin('git'));
        $this->assertSame(
            ['Plugin "git" needs "diff", which is not installed or is disabled'],
            \array_map(static fn (ConfigWarning $warning): string => \strtr($warning->key, $warning->params), $manager->warnings),
        );
    }
}
