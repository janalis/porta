<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Points PORTA_EMBEDDED_PLUGINS at the repo's real `plugins/` dir for the
 * duration of each test and restores the suite-wide `/nonexistent` pin after —
 * for E2e suites that need the bundled plugins (the viewer routes live there).
 * An attribute-hook trait like {@see PreservesPath}, so classes keep their own
 * setUp/tearDown.
 */
trait UsesBundledPlugins
{
    private ?string $savedEmbeddedPlugins = null;

    #[Before]
    protected function pointAtBundledPlugins(): void
    {
        $current = \getenv('PORTA_EMBEDDED_PLUGINS');
        $this->savedEmbeddedPlugins = false === $current ? null : $current;
        \putenv('PORTA_EMBEDDED_PLUGINS='.\dirname(__DIR__, levels: 2).'/plugins');
    }

    #[After]
    protected function restoreBundledPlugins(): void
    {
        \putenv('PORTA_EMBEDDED_PLUGINS='.($this->savedEmbeddedPlugins ?? ''));
    }
}
