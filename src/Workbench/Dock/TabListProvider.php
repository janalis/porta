<?php

declare(strict_types=1);

namespace Porta\Workbench\Dock;

use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;

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
        return array_map(fn (Buffer $b) => [
            'name' => $b->isHelp() ? $this->translator->trans('Documentation') : basename($b->path),
            'dirty' => $b->isDirty(),
            'active' => $b === $this->buffers->active(),
        ], $this->buffers->all());
    }
}
