<?php

declare(strict_types=1);

namespace Porta\Container;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Marks a widget as a chrome root swept by the live-setting appliers — a
 * theme/locale/cursor/tab-position switch must relabel or recolor the whole
 * tree, and widgets cache their rendered lines with only an upward-bubbling
 * invalidate(), so {@see \Porta\Workbench\ChromeInvalidator} clears every cache
 * downward from these roots. Extends `#[AutoconfigureTag]`, so the
 * `porta.chrome_root` tag applies natively with no central registration;
 * the invalidator injects the tag as a `#[AutowireIterator]`
 * (the {@see AsEager} pattern), so membership travels with the class instead of
 * a hand-maintained list in {@see \Porta\Ide}.
 *
 * Order-independent: invalidateAll() only clears caches, so no priority is
 * carried. The one non-class root ($main, the porta.widget.main getter service)
 * is tagged explicitly at its def, the {@see \Symfony\Component\Tui\Input\Keybindings}
 * exception pattern.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsChromeRoot extends AutoconfigureTag
{
    public function __construct()
    {
        parent::__construct('porta.chrome_root');
    }
}
