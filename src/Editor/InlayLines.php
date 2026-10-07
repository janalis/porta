<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Which inlay hints a line shows, and where: the one reader every consumer of
 * the hints goes through — the renderer, the caret, the mouse, the hover card
 * — so none of them can disagree about which hints exist.
 *
 * The store keeps everything the server sent; this applies the display
 * options at read time, so changing one repaints without asking again.
 */
final readonly class InlayLines
{
    public function __construct(
        private DecorationStore $store,
        private EditorDisplayOptions $display,
    ) {}

    /**
     * The hints a line shows, whatever the mode: the hover card of
     * {@see InlayHintMode::Tooltip} lists the same ones the line would draw.
     *
     * @return list<InlayHint>
     */
    public function shown(int $line): array
    {
        return $this->store->inlayHintsAt($line);
    }

    /** The hints drawn INTO the line: only in {@see InlayHintMode::Inline}. */
    public function forLine(Document $doc, int $line, ?int $wrapWidth = null): InlayLine
    {
        if (InlayHintMode::Inline !== $this->display->inlayHints) {
            return InlayLine::none();
        }

        return InlayLine::place($this->shown($line), $doc, $line, $wrapWidth);
    }
}
