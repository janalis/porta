<?php

declare(strict_types=1);

namespace Porta\Container;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Injects one of the composite widget-tree's duplicate-typed sub-widgets by its
 * slot name. `WorkspaceLayout` yields several `ContainerWidget`/`SeparatorWidget`
 * instances (editorBody, main, the git/settings areas, the pane separators) that
 * autowiring can't tell apart by type; each is exposed as a `porta.widget.<slot>`
 * getter service, and this attribute names which one a constructor param wants —
 * so the disambiguation lives at the consumer that knows the answer, not in a
 * central bind. Extends `#[Autowire]`, so the existing AutowirePass resolves it
 * natively (no compiler pass), the way `#[AutowireIterator]` does for tags.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class LayoutSlot extends Autowire
{
    public function __construct(string $slot)
    {
        parent::__construct(service: 'porta.widget.'.$slot);
    }
}
