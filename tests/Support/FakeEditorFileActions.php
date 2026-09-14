<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\FindMode;
use Porta\Widget\EditorFileActionHandler;

/** Per-method closure fakes so tests wire only the hooks a scenario needs. */
final readonly class FakeEditorFileActions implements EditorFileActionHandler
{
    public function __construct(
        private ?\Closure $save = null,
        private ?\Closure $saveAll = null,
        private ?\Closure $copy = null,
        private ?\Closure $openFind = null,
        private ?\Closure $openGotoFile = null,
    ) {}

    public function save(): void
    {
        if (null !== $this->save) {
            ($this->save)();
        }
    }

    public function saveAll(): void
    {
        if (null !== $this->saveAll) {
            ($this->saveAll)();
        }
    }

    public function copy(string $text): void
    {
        if (null !== $this->copy) {
            ($this->copy)($text);
        }
    }

    public function openFind(): void
    {
        if (null !== $this->openFind) {
            ($this->openFind)(FindMode::Find);
        }
    }

    public function openFindReplace(): void
    {
        if (null !== $this->openFind) {
            ($this->openFind)(FindMode::Replace);
        }
    }

    public function openGotoFile(): void
    {
        if (null !== $this->openGotoFile) {
            ($this->openGotoFile)();
        }
    }
}
