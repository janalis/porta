<?php

declare(strict_types=1);

namespace Porta\Ui;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Recursive render-cache invalidation for the IDE chrome. Widgets cache their
 * rendered lines and invalidate() only bubbles up, so a change that must
 * relabel or recolor the whole tree (live theme/locale/cursor/tab-position
 * switches) clears every cache downward from the registered roots. The roots
 * are registered once at boot (they include hand-assembled layout containers
 * the container does not own yet).
 */
final class ChromeInvalidator
{
    /** @var list<AbstractWidget> */
    private array $roots = [];

    /** @param list<AbstractWidget> $roots */
    public function register(array $roots): void
    {
        $this->roots = $roots;
    }

    /** Clear every registered root's cache tree and everything below. */
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
