<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\EditorRefactorHandler;

final class FakeEditorRefactor implements EditorRefactorHandler
{
    public function __construct(
        private readonly ?\Closure $requestPrepareRename = null,
        private readonly ?\Closure $requestCodeActions = null,
        private readonly ?\Closure $requestReformat = null,
    ) {}

    public function requestPrepareRename(int $line, int $col, string $word): void
    {
        if (null !== $this->requestPrepareRename) {
            ($this->requestPrepareRename)($line, $col, $word);
        }
    }

    public function requestCodeActions(int $line, int $col): void
    {
        if (null !== $this->requestCodeActions) {
            ($this->requestCodeActions)($line, $col);
        }
    }

    public function requestReformat(): void
    {
        if (null !== $this->requestReformat) {
            ($this->requestReformat)();
        }
    }
}
