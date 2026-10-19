<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Use-case plain strings keep backslashes; Markdown and JSON own distinct lexical modes. */
final class UsecaseQuotedText
{
    private string $quote = '';
    private bool $markdown = false;
    private int $skip = 0;
    private ?MermaidQuotedText $json = null;

    public function json(): void
    {
        $this->json = new MermaidQuotedText('"');
    }

    public function quoted(): bool
    {
        return match (true) {
            null !== $this->json => $this->json->quoted,
            default => '' !== $this->quote || $this->markdown || $this->skip > 0,
        };
    }

    public function multiline(): bool
    {
        return $this->markdown;
    }

    public function advance(string $char, string $next): bool
    {
        if (null !== $this->json) {
            $this->json->advance($char);

            return !$this->json->quoted;
        }

        return $this->label($char, $next);
    }

    private function label(string $char, string $next): bool
    {
        if ($this->skip > 0) {
            --$this->skip;

            return false;
        }
        $structural = !$this->markdown && '' === $this->quote && '"' !== $char && "'" !== $char;
        match (true) {
            $this->markdown => $this->closeMarkdown($char, $next),
            '' !== $this->quote => $this->closePlain($char),
            '"' === $char && '`' === $next => $this->toggleMarkdown(),
            '"' === $char || "'" === $char => $this->quote = $char,
            default => null,
        };

        return $structural;
    }

    private function closeMarkdown(string $char, string $next): void
    {
        if ('`' === $char && '"' === $next) {
            $this->toggleMarkdown();
        }
    }

    private function toggleMarkdown(): void
    {
        $this->markdown = !$this->markdown;
        $this->skip = 1;
    }

    private function closePlain(string $char): void
    {
        if ($char === $this->quote) {
            $this->quote = '';
        }
    }
}
