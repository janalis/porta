<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\EditorNavigationHandler;

final class FakeEditorNavigation implements EditorNavigationHandler
{
    public function __construct(
        private readonly ?\Closure $requestDefinition = null,
        private readonly ?\Closure $requestImplementation = null,
        private readonly ?\Closure $requestTypeDefinition = null,
        private readonly ?\Closure $navigateBack = null,
        private readonly ?\Closure $requestHover = null,
        private readonly ?\Closure $requestReferences = null,
        private readonly ?\Closure $requestFileStructure = null,
        private readonly ?\Closure $requestPeek = null,
        private readonly ?\Closure $requestCallHierarchy = null,
        private readonly ?\Closure $requestOutgoingCalls = null,
    ) {}

    public function requestDefinition(int $line, int $utf16Col): void
    {
        if (null !== $this->requestDefinition) {
            ($this->requestDefinition)($line, $utf16Col);
        }
    }

    public function requestImplementation(int $line, int $utf16Col): void
    {
        if (null !== $this->requestImplementation) {
            ($this->requestImplementation)($line, $utf16Col);
        }
    }

    public function requestTypeDefinition(int $line, int $utf16Col): void
    {
        if (null !== $this->requestTypeDefinition) {
            ($this->requestTypeDefinition)($line, $utf16Col);
        }
    }

    public function navigateBack(): void
    {
        if (null !== $this->navigateBack) {
            ($this->navigateBack)();
        }
    }

    public function requestHover(int $line, int $col): void
    {
        if (null !== $this->requestHover) {
            ($this->requestHover)($line, $col);
        }
    }

    public function requestReferences(int $line, int $col): void
    {
        if (null !== $this->requestReferences) {
            ($this->requestReferences)($line, $col);
        }
    }

    public function requestFileStructure(): void
    {
        if (null !== $this->requestFileStructure) {
            ($this->requestFileStructure)();
        }
    }

    public function requestPeek(int $line, int $col): void
    {
        if (null !== $this->requestPeek) {
            ($this->requestPeek)($line, $col);
        }
    }

    public function requestCallHierarchy(int $line, int $col): void
    {
        if (null !== $this->requestCallHierarchy) {
            ($this->requestCallHierarchy)($line, $col);
        }
    }

    public function requestOutgoingCalls(int $line, int $col): void
    {
        if (null !== $this->requestOutgoingCalls) {
            ($this->requestOutgoingCalls)($line, $col);
        }
    }
}
