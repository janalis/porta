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
     * Each kind answers to its own setting; a parameter's name, by default,
     * only before a literal argument — read from the line as it is now. A
     * by-reference parameter (`&name:`) always says so: that it may write
     * back is not something the argument shows.
     *
     * @return list<InlayHint>
     */
    public function shown(Document $doc, int $line): array
    {
        $hints = $this->store->inlayHintsAt($line);
        if ([] === $hints) {
            return [];
        }
        $text = $doc->getLine($line);

        return \array_values(\array_filter($hints, fn (InlayHint $hint): bool => match ($hint->kind) {
            InlayHintKind::Type => $this->display->inlayTypeHints,
            InlayHintKind::Parameter => match ($this->display->inlayParameterHints) {
                InlayParameterHints::None => false,
                InlayParameterHints::All => true,
                InlayParameterHints::Literals => \str_starts_with($hint->label, '&') || InlayArgument::isLiteral($text, $hint->col),
            },
            null => true,
        }));
    }

    /** The hints drawn INTO the line: only in {@see InlayHintMode::Inline}. */
    public function forLine(Document $doc, int $line, ?int $wrapWidth = null): InlayLine
    {
        if (InlayHintMode::Inline !== $this->display->inlayHints) {
            return InlayLine::none();
        }

        return InlayLine::place($this->shown($doc, $line), $doc, $line, $wrapWidth);
    }
}
