<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

/**
 * One entry in an application-menu dropdown: a label, its keyboard shortcut for
 * display, and the closure to run when chosen. A separator carries none of these.
 * An optional {@see $isEnabled} predicate greys the item out (and blocks running
 * it) when it returns false — e.g. document actions while no file is open.
 */
final readonly class MenuItem
{
    /**
     * @param (\Closure(): void)|null $run
     * @param (\Closure(): bool)|null $isEnabled
     */
    public function __construct(
        public string $label,
        public string $shortcut,
        public ?\Closure $run,
        public bool $separator = false,
        public ?\Closure $isEnabled = null,
    ) {}

    public static function separator(): self
    {
        return new self('', '', null, true);
    }

    /** Whether the item is currently selectable/runnable (no predicate → always enabled). */
    public function enabled(): bool
    {
        return null === $this->isEnabled || ($this->isEnabled)();
    }
}
