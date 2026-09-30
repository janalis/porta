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
 *
 * The live-preview claim is the same question with a different answer — the
 * file still opens as text, with the pane beside it — so it rides the same
 * seam rather than a second one-method interface for the same consumer.
 */
interface ViewerProviderSource
{
    public function viewerFor(string $path): ?string;

    /** The pane that previews $path beside the editor, or null ({@see \Porta\Plugin\Api\PluginApi::registerPreview()}). */
    public function previewFor(string $path): ?string;
}
