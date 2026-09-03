<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\EditorHost;
use Porta\Input\TextInput;

/**
 * Records every EditorHost call through the given recorder closure (so a test
 * keeps one call sink across all its editor helpers); the query methods answer
 * from public fixture fields.
 */
final class FakeEditorHost implements EditorHost
{
    /** @param \Closure(string, array<int, mixed>): void $recorder */
    public function __construct(
        private readonly \Closure $recorder,
        public int $rows = 5,
        public ?string $comment = '//',
        public bool $autoPairs = true,
    ) {}

    public function invalidate(): void
    {
        ($this->recorder)('invalidate', []);
    }

    public function notifyChange(): void
    {
        ($this->recorder)('notifyChange', []);
    }

    public function openPrompt(string $label, string $initial, callable $onSubmit, bool $danger = false): void
    {
        ($this->recorder)('openPrompt', [$label, $initial, $onSubmit]);
    }

    public function jumpTo(int $line, int $utf16Col): void
    {
        ($this->recorder)('jumpTo', [$line, $utf16Col]);
    }

    public function setSignatureHint(?string $hint): void
    {
        ($this->recorder)('setSignatureHint', [$hint]);
    }

    public function fireCopy(string $text): void
    {
        ($this->recorder)('fireCopy', [$text]);
    }

    public function fireSignatureHelp(int $line, int $utf16Col): void
    {
        ($this->recorder)('fireSignatureHelp', [$line, $utf16Col]);
    }

    public function requestRename(int $line, int $utf16Col, string $word): void
    {
        ($this->recorder)('requestRename', [$line, $utf16Col, $word]);
    }

    /** Soft wrap off unless a test says otherwise — the editor's own default. */
    public ?int $wrapWidth = null;

    public function wrapWidth(): ?int
    {
        return $this->wrapWidth;
    }

    public function contentRows(): int
    {
        return $this->rows;
    }

    public function commentPrefix(): ?string
    {
        return $this->comment;
    }

    public function autoPairsEnabled(): bool
    {
        return $this->autoPairs;
    }

    public function isInsertable(string $data): bool
    {
        return TextInput::isPrintable($data);
    }
}
