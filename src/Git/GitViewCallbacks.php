<?php

declare(strict_types=1);

namespace Porta\Git;

use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The host hooks {@see GitViewController} calls back into: how to rebuild the
 * editor layout when the git view closes, lifecycle notifications, and the
 * persisted diff-pref + clipboard bridges. Bundled so the controller takes one
 * callbacks group instead of six separate closures.
 */
final readonly class GitViewCallbacks
{
    /**
     * @param \Closure(): list<AbstractWidget>                    $editArea        the main container's normal children (tree, separator, editor pane), in the current writing direction's order
     * @param \Closure(): void                                    $onClosed        restore focus/render after the swap back
     * @param \Closure(): void                                    $onCommitted     refresh gutter marks and branch after a commit
     * @param \Closure(): array{split: bool, hideWhitespace: bool} $loadDiffPrefs  persisted display toggles
     * @param \Closure(bool, bool): void                          $saveDiffPrefs   persist (split, hideWhitespace)
     * @param \Closure(string): void                              $copyToClipboard OSC 52 copy
     */
    public function __construct(
        public \Closure $editArea,
        public \Closure $onClosed,
        public \Closure $onCommitted,
        public \Closure $loadDiffPrefs,
        public \Closure $saveDiffPrefs,
        public \Closure $copyToClipboard,
    ) {}
}
