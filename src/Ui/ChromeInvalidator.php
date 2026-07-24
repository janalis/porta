<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Container\AsIdeService;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Recursive render-cache invalidation for the IDE chrome. Widgets cache their
 * rendered lines and invalidate() only bubbles up, so a change that must
 * relabel or recolor the whole tree (live theme/locale/cursor/tab-position
 * switches) clears every cache downward from the roots. The roots are the
 * `#[AsChromeRoot]`-tagged widgets, injected as a tagged iterator so membership
 * lives on each widget instead of a register() list (order-independent — this
 * only clears caches).
 */
#[AsIdeService]
final class ChromeInvalidator
{
    /** @param iterable<AbstractWidget> $roots the `porta.chrome_root`-tagged chrome widgets */
    public function __construct(
        #[AutowireIterator('porta.chrome_root')]
        private readonly iterable $roots = [],
    ) {}

    /** Clear every chrome root's cache tree and everything below. */
    public function invalidateAll(): void
    {
        foreach ($this->roots as $widget) {
            $this->invalidate($widget);
        }
    }

    /** Clear one widget's cache tree recursively. */
    public function invalidate(AbstractWidget $widget): void
    {
        $widget->clearRenderCache();
        if ($widget instanceof ContainerWidget) {
            foreach ($widget->all() as $child) {
                $this->invalidate($child);
            }
        }
    }
}
