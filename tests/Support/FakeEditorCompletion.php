<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\Document;
use Porta\Editor\TemplateCatalog;
use Porta\Widget\EditorCompletionHandler;

final class FakeEditorCompletion implements EditorCompletionHandler
{
    public function __construct(
        private readonly ?\Closure $requestCompletion = null,
        private readonly ?\Closure $resolveCompletion = null,
        /** Called as ($rawItem, $onDetail); a scenario answers by invoking $onDetail. */
        private readonly ?\Closure $resolveCompletionDetail = null,
        private readonly ?\Closure $requestSignatureHelp = null,
        private readonly ?\Closure $scheduleDidChange = null,
        private readonly ?\Closure $viewportScrolled = null,
        /** Overrides the built-in templates a scenario would otherwise get. */
        private readonly ?\Closure $templatesFor = null,
    ) {}

    /** @return array<string, string> */
    public function templatesFor(string $languageId): array
    {
        if (null === $this->templatesFor) {
            return TemplateCatalog::forLanguage($languageId);
        }

        /** @var array<string, string> */
        return ($this->templatesFor)($languageId);
    }

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

    public function resolveCompletionDetail(array $item, \Closure $onDetail): void
    {
        if (null !== $this->resolveCompletionDetail) {
            ($this->resolveCompletionDetail)($item, $onDetail);
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

    public function viewportScrolled(): void
    {
        if (null !== $this->viewportScrolled) {
            ($this->viewportScrolled)();
        }
    }
}
