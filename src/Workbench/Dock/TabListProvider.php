<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Ui\Glyphs;

/** The tab strip's rows, pulled at render time from the buffer list. */
final class TabListProvider
{
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly Translator $translator,
    ) {}

    /** @return list<array{name: string, dirty: bool, active: bool}> */
    public function tabs(): array
    {
        return array_map(
            fn (Buffer $b) => [
                // The pin rides the NAME rather than becoming a fourth field: the
                // strip's width arithmetic already measures the label, and adding a
                // column to it is the one change that needs no new maths.
                'name' => ($b->pinned ? Glyphs::PINNED.' ' : '').$this->title($b),
                'dirty' => $b->isDirty(),
                'active' => $b === $this->buffers->active(),
            ],
            $this->buffers->all(),
        );
    }

    /** Help pages share one "Documentation" tab name; a titled buffer (the architecture graph) shows its translated title; everything else its basename. */
    private function title(Buffer $b): string
    {
        if ('help' === $b->paneId) {
            return $this->translator->trans('Documentation');
        }

        return null !== $b->title ? $this->translator->trans($b->title) : basename($b->path);
    }
}
