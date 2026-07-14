<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\EditorFileActionHandler;

/** Per-method closure fakes so tests wire only the hooks a scenario needs. */
final class FakeEditorFileActions implements EditorFileActionHandler
{
    public function __construct(
        private readonly ?\Closure $save = null,
        private readonly ?\Closure $saveAll = null,
        private readonly ?\Closure $copy = null,
        private readonly ?\Closure $openFind = null,
        private readonly ?\Closure $openGotoFile = null,
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

    public function openFind(bool $withReplace): void
    {
        if (null !== $this->openFind) {
            ($this->openFind)($withReplace);
        }
    }

    public function openGotoFile(): void
    {
        if (null !== $this->openGotoFile) {
            ($this->openGotoFile)();
        }
    }
}
