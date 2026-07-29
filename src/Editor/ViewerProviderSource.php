<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Which viewer (if any) claims a file path — the routing seam
 * {@see BufferService::openFile()} consults before falling through to the
 * text editor. Owned by the CONSUMING namespace (the
 * {@see \Porta\Highlight\SyntaxHighlightProviderSource} precedent), so the
 * editor never depends on `Porta\Plugin` even though the claims come from
 * plugin registrations ({@see \Porta\Plugin\Api\PluginApi::registerViewer()}).
 *
 * The returned id names a built-in viewer pane ('image', 'graph'); unknown
 * ids fall through to the text path, leaving room for a future
 * widget-registration API without breaking this contract.
 */
interface ViewerProviderSource
{
    public function viewerFor(string $path): ?string;
}
