<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\EditorViewport;
use Porta\Editor\GutterHint;
use Porta\Editor\GutterPart;
use Porta\I18n\Translator;
use Porta\Ui\Tooltip;
use Porta\Widget\Render\ErrorStripe;
use Porta\Widget\Render\StripeMark;
use Porta\Widget\Render\StripeMarks;

/**
 * What the pointer is on in the editor's two mark columns — the gutter's
 * change strip and marker column, and the marker stripe in the right margin
 * — read off the last render's geometry. The hover tint ({@see
 * \Porta\Widget\CodeEditorWidget::hoverGutter()}) and the tooltip ask the
 * same questions, so they are answered once, here, and the widget's method
 * count stays put.
 */
final readonly class GutterPointer
{
    /**
     * @param \Closure(int): ?int $documentLineAt the document line drawn at a pane-relative row (sticky- and fold-aware)
     */
    public function __construct(
        private EditorViewport $viewport,
        private DecorationStore $decorations,
        private bool $vcsGutter,
        private int $lineCount,
        private \Closure $documentLineAt,
    ) {}

    /**
     * The document line and the gutter part under a pane-relative cell; a
     * null part off both mark columns.
     *
     * @return array{int, ?GutterPart}
     */
    public function partAt(int $relRow, int $relCol): array
    {
        $docLine = $relRow >= 0 && $relCol >= 0 && $relCol < $this->viewport->gutterWidth ? ($this->documentLineAt)($relRow) : null;
        if (null === $docLine) {
            return [-1, null];
        }

        return [
            $docLine,
            match (true) {
                0 === $relCol && $this->vcsGutter && \array_key_exists($docLine, $this->decorations->vcsMarks) => GutterPart::Change,
                $relCol === ($this->viewport->gutterWidth - 3) => GutterPart::Marker, // then the fold column, then a space
                default => null,
            },
        ];
    }

    /**
     * The marker stripe's mark under a pane-relative cell: its kind and the
     * first line of that kind in the row's slice of the file.
     *
     * @return array{kind: StripeMark, line: int, severity: ?DiagnosticSeverity, change: ?ChangeMarkKind}|null
     */
    public function stripeMarkAt(int $relRow, int $relCol): ?array
    {
        return $relRow >= 0 && $relCol >= $this->viewport->stripeCol && $relRow < $this->viewport->renderRows
            ? ErrorStripe::markAt(StripeMarks::from($this->decorations, $this->vcsGutter), $relRow, $this->viewport->renderRows, $this->lineCount)
            : null;
    }

    /**
     * What the mark under a pane-relative cell means, in words, or null off
     * every mark — the glyphs and the stripe's colours say it only to someone
     * who already knows.
     */
    public function tooltip(int $relRow, int $relCol, bool $clickShowsChange, Translator $translator): ?Tooltip
    {
        [$docLine, $part] = $this->partAt($relRow, $relCol);
        $hint = null === $part ? null : GutterHint::for($this->decorations, $docLine, $part, $clickShowsChange, $translator);
        if (null !== $part && null !== $hint) {
            return new Tooltip('gutter:'.$part->name.':'.$docLine, $hint, $relRow, $relCol, $relCol + 1);
        }
        $mark = $this->stripeMarkAt($relRow, $relCol);
        if (null === $mark) {
            return null;
        }
        $what = $translator->trans($mark['kind']->label());

        return new Tooltip('stripe:'.$relRow, $translator->trans('%what% · line %line%', ['%what%' => $what, '%line%' => $mark['line'] + 1]), $relRow, $relCol, $relCol + 1);
    }
}
