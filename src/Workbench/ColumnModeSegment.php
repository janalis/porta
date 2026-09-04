<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Says so while keyboard column selection is on.
 *
 * A mode whose state you cannot see is a trap — you press an arrow, get a
 * rectangle you did not expect, and have nothing to look at to explain it. A
 * toast would not do: it expires, and the mode does not.
 *
 * Rides the existing contributor channel rather than the status bar's own
 * segments, which is what that channel is for and which leaves the right-hand
 * cells' column arithmetic alone.
 */
#[AsIdeService]
#[AsTaggedItem(priority: 250)]
final readonly class ColumnModeSegment implements StatusSegment
{
    public function __construct(
        private CodeEditorWidget $editor,
        private Translator $translator,
    ) {}

    #[\Override]
    public function statusCells(): array
    {
        return (
            $this->editor->columnMode()
                ? [new StatusCell($this->translator->trans('COLUMN'), StatusTone::Accent)]
                : []
        );
    }
}
