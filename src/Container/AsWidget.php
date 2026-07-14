<?php

declare(strict_types=1);

namespace Porta\Container;

/**
 * Marks a widget service with its widget-tree id and (optionally) its flex
 * style. Autoconfiguration (config/services.php) converts the attribute into
 * the setId()/setStyle() method calls the container applies after construction,
 * so per-widget config lives on the widget class instead of in wiring config.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsWidget
{
    public function __construct(
        public string $id,
        public ?int $flex = null,
    ) {}
}
