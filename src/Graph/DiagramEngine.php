<?php

declare(strict_types=1);

namespace Porta\Graph;

/**
 * Turns a diagram source into PNG bytes asynchronously — everything the graph
 * pane needs to show a PICTURE, and nothing more. Graphviz answers this through
 * {@see LayoutEngine}, which extends it with the geometry only `dot` can give;
 * PlantUML answers it alone, which is exactly why a `.puml` tab has no node
 * selection, no counts and no cell rendering.
 *
 * Tests substitute fakes, so no binary is anywhere near the suite.
 */
interface DiagramEngine
{
    /** The resolved tool path, or null when it is not installed. */
    public function binary(): ?string;

    /**
     * The dpi at which this tool draws a diagram at its natural size — the
     * viewer's 1:1. Graphviz's is 72 (one PostScript point per pixel, which is
     * what lets its bitmap and its layout share coordinates); PlantUML's is 96.
     */
    public function baseDpi(): int;

    /**
     * Render $source to PNG bytes at $dpi. Bytes, not a file: the child's
     * stdout goes straight to the decoder, so there is nothing to clean up.
     * Multiples of {@see baseDpi()} oversample for zoom.
     *
     * One slot, latest wins: a superseded call goes silent.
     *
     * @param \Closure(string): void $onPng   the PNG bytes
     * @param \Closure(string): void $onError the tool's stderr (or why it could not run)
     */
    public function render(string $source, int $dpi, \Closure $onPng, \Closure $onError): void;
}
