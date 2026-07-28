<?php

declare(strict_types=1);

namespace Porta\Container;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Marks a service as a member of the `porta.ide` ServiceLocator — the single
 * public boundary {@see \Porta\Ide} resolves everything through (and a handful
 * of tests reach reflectively). Extends `#[AutoconfigureTag]`, so Symfony's
 * autoconfiguration applies the `porta.ide_service` tag natively — no central
 * registration (the {@see LayoutSlot}-extends-`#[Autowire]` pattern); the
 * locator is built from that tag, keyed by the class's service id (its FQCN),
 * so `$locator->get(X::class)` resolves it.
 *
 * Membership travels with the class instead of a central list, so a moved or
 * renamed member carries its own membership and never drifts out of a
 * hand-maintained manifest.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsIdeService extends AutoconfigureTag
{
    public function __construct()
    {
        parent::__construct('porta.ide_service');
    }
}
