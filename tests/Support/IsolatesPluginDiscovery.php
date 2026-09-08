<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * The environment a plugin-MACHINERY suite needs: no ambient plugin, and
 * `PORTA_EMBEDDED_PLUGINS` put back afterwards.
 *
 * Two things, because they are one situation. A suite that exercises discovery,
 * listings and the Settings nav must opt out of the suite-wide `tempest`
 * highlighting fixture that `PORTA_HOME` installs — a permanently present
 * plugin appears in every listing and shifts the index-based navigation. And it
 * points `PORTA_EMBEDDED_PLUGINS` at a fixture dir per test, so the value it
 * found has to be restored: putenv is process-global and paratest runs a
 * worker's methods sequentially, so a leak silently uncolours or re-plugins
 * every later test in that worker.
 *
 * An attribute-hook trait like {@see PreservesPath} and {@see UsesBundledPlugins},
 * so a class keeps its own setUp/tearDown. Its properties are named for this
 * trait rather than for the variables, because {@see UsesBundledPlugins} already
 * owns a `$savedEmbeddedPlugins` and two traits declaring one property is a
 * fatal, not a merge.
 */
trait IsolatesPluginDiscovery
{
    private ?string $savedDiscoveryHome = null;

    private string $savedDiscoveryEmbedded = '';

    #[Before]
    protected function isolatePluginDiscovery(): void
    {
        $home = getenv('PORTA_HOME');
        $this->savedDiscoveryHome = false === $home ? null : $home;
        putenv('PORTA_HOME=/nonexistent');
        $this->savedDiscoveryEmbedded = (string) getenv('PORTA_EMBEDDED_PLUGINS');
    }

    #[After]
    protected function restorePluginDiscovery(): void
    {
        putenv('PORTA_EMBEDDED_PLUGINS='.$this->savedDiscoveryEmbedded);
        if (null === $this->savedDiscoveryHome) {
            return;
        }
        putenv('PORTA_HOME='.$this->savedDiscoveryHome);
        $this->savedDiscoveryHome = null;
    }
}
