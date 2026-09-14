<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\EditorNavigationHandler;

final readonly class FakeEditorNavigation implements EditorNavigationHandler
{
    public function __construct(
        private ?\Closure $requestDefinition = null,
        private ?\Closure $requestImplementation = null,
        private ?\Closure $requestTypeDefinition = null,
        private ?\Closure $navigateBack = null,
        private ?\Closure $requestHover = null,
        private ?\Closure $requestReferences = null,
        private ?\Closure $requestFileStructure = null,
        private ?\Closure $requestPeek = null,
        private ?\Closure $requestCallHierarchy = null,
        private ?\Closure $requestOutgoingCalls = null,
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
