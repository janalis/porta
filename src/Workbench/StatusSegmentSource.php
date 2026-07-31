<?php

declare(strict_types=1);

namespace Porta\Workbench;

/**
 * The status-bar segments plugins contribute.
 *
 * Owned by the CONSUMING namespace (the {@see \Porta\Editor\ViewerProviderSource}
 * precedent), so `Porta\Workbench` never depends on `Porta\Plugin`.
 *
 * The texts arrive already EVALUATED and already guarded: this is pulled once
 * per render, so the implementation runs each plugin's closure inside that
 * plugin's fault barrier and reports a throwing one at most once — the
 * reasoning menu `enabledWhen` closures use, for the same reason.
 */
interface StatusSegmentSource
{
    /** @return list<string> the non-empty segments, in plugin load order */
    public function statusSegments(): array;
}
