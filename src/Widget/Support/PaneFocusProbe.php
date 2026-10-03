<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * A tab strip asking whether the pane it heads has the keys: its shown
 * tab turns from grey to the selection blue when it does ({@see \Porta\Widget\Render\TabPillStyle}).
 * A strip is never focused itself, so the answer comes from whoever knows the
 * pane — handed in, and asked each frame, since focus moves without telling
 * the strip.
 */
trait PaneFocusProbe
{
    /** @var (\Closure(): bool)|null */
    private ?\Closure $paneFocusProbe = null;

    /** @param \Closure(): bool $probe */
    public function setFocusProbe(\Closure $probe): void
    {
        $this->paneFocusProbe = $probe;
    }

    private function paneFocused(): bool
    {
        return null !== $this->paneFocusProbe && ($this->paneFocusProbe)();
    }
}
