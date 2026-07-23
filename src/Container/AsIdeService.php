<?php

declare(strict_types=1);

namespace Porta\Container;

/**
 * Marks a service as a member of the `porta.ide` ServiceLocator — the single
 * public boundary {@see \Porta\Ide} resolves everything through (and a handful
 * of tests reach reflectively). Autoconfiguration (config/services.php) tags it
 * `porta.ide_service`; the locator is built from that tag, keyed by the class's
 * service id (its FQCN), so `$locator->get(X::class)` resolves it.
 *
 * Membership travels with the class instead of a central list, so a moved or
 * renamed member carries its own membership and never drifts out of a
 * hand-maintained manifest.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsIdeService {}
