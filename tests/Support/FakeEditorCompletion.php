<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\Document;
use Porta\Widget\EditorCompletionHandler;

final class FakeEditorCompletion implements EditorCompletionHandler
{
    public function __construct(
        private readonly ?\Closure $requestCompletion = null,
        private readonly ?\Closure $resolveCompletion = null,
        private readonly ?\Closure $requestSignatureHelp = null,
        private readonly ?\Closure $scheduleDidChange = null,
    ) {}

    public function requestCompletion(int $line, int $utf16Col): void
    {
        if (null !== $this->requestCompletion) {
            ($this->requestCompletion)($line, $utf16Col);
        }
    }

    public function resolveCompletion(array $item): void
    {
        if (null !== $this->resolveCompletion) {
            ($this->resolveCompletion)($item);
        }
    }

    public function requestSignatureHelp(int $line, int $col): void
    {
        if (null !== $this->requestSignatureHelp) {
            ($this->requestSignatureHelp)($line, $col);
        }
    }

    public function scheduleDidChange(Document $doc): void
    {
        if (null !== $this->scheduleDidChange) {
            ($this->scheduleDidChange)($doc);
        }
    }
}
