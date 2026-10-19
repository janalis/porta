<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** YAML scalars and comments shield configuration delimiters from the statement lexer. */
final class MermaidConfigurationText
{
    public private(set) bool $opensAnchor = false;
    public private(set) bool $opensQuote = false;
    public private(set) bool $opensTag = false;
    public private(set) bool $plain = false;

    private string $quote = '';
    private string $tag = '';
    private bool $escaped = false;
    private bool $anchor = false;
    public private(set) bool $comment = false;
    private bool $boundary = true;
    private bool $separation = false;
    private bool $afterQuote = false;
    private string $previous = '';
    private int $indent = 0;
    private ?int $parentIndent = null;
    private bool $leading = true;
    private ?int $blockIndent = null;
    private bool $blockHeader = false;

    /** Whether this character may open or close a structural delimiter. */
    public function advance(string $char, ?int $parentIndent = null): bool
    {
        $this->parentIndent = $parentIndent;
        $this->opensAnchor = false;
        $this->opensQuote = false;
        $this->opensTag = false;
        $this->plain = false;
        $previous = $this->previous;
        $this->previous = $char;
        if ($this->separation && \ctype_space($char)) {
            $this->boundary = true;
        }
        $this->separation = false;

        return match (true) {
            $this->block($char) => false,
            $this->comment => $this->comment($char),
            '' !== $this->quote => $this->quoted($char),
            '' !== $this->tag => $this->tagged($char),
            $this->anchor => $this->anchored($char),
            default => $this->scalar($char, $previous),
        };
    }

    private function comment(string $char): bool
    {
        $this->comment = "\n" !== $char;
        $this->boundary = !$this->comment;

        return false;
    }

    private function quoted(string $char): bool
    {
        match (true) {
            $this->escaped => $this->escaped = false,
            '"' === $this->quote && '\\' === $char => $this->escaped = true,
            $char === $this->quote => $this->closeQuote(),
            default => null,
        };

        return false;
    }

    private function closeQuote(): void
    {
        $this->quote = '';
        $this->boundary = true;
        $this->afterQuote = true;
    }

    private function scalar(string $char, string $previous): bool
    {
        return match (true) {
            '#' === $char && \ctype_space($previous) => $this->beginComment(),
            $this->boundary && '&' === $char => $this->beginAnchor(),
            $this->boundary && '!' === $char => $this->beginTag(),
            $this->boundary && ('"' === $char || "'" === $char) => $this->beginQuote($char),
            default => $this->punctuation($char),
        };
    }

    private function beginTag(): bool
    {
        $this->tag = '!';
        $this->opensTag = true;

        return true;
    }

    private function tagged(string $char): bool
    {
        if ('<' === $this->tag || ('!' === $this->tag && '<' === $char)) {
            $this->tag = '>' === $char ? '' : '<';
            $this->boundary = '>' === $char || $this->boundary;

            return false;
        }
        $ended = \ctype_space($char) || \str_contains(',]}{[', $char);
        $this->tag = $ended ? '' : 'short';
        $this->boundary = $ended || $this->boundary;

        return $ended && $this->punctuation($char);
    }

    private function beginAnchor(): bool
    {
        $this->anchor = true;
        $this->opensAnchor = true;

        return false;
    }

    private function anchored(string $char): bool
    {
        if (!\ctype_space($char) && !\str_contains(',[]{}', $char)) {
            return false;
        }
        $this->anchor = false;
        $this->boundary = true;

        return $this->punctuation($char);
    }

    private function beginComment(): bool
    {
        $this->comment = true;

        return false;
    }

    private function beginQuote(string $char): bool
    {
        $this->quote = $char;
        $this->opensQuote = true;

        return false;
    }

    private function punctuation(string $char): bool
    {
        $this->plain = !\ctype_space($char);
        if ($this->boundary && ('|' === $char || '>' === $char)) {
            $this->blockIndent = $this->parentIndent ?? $this->indent;
            $this->blockHeader = true;
        }
        $colon = ':' === $char;
        $this->separation = $colon || ($this->boundary && \in_array($char, ['-', '?'], strict: true));
        $this->boundary = match (true) {
            $colon => $this->afterQuote,
            "\n" === $char || \str_contains('{[,', $char) => true,
            !\ctype_space($char) => false,
            default => $this->boundary,
        };
        if (!\ctype_space($char)) {
            $this->afterQuote = false;
        }

        return !\str_contains('()<>', $char);
    }

    /** An indented block scalar owns its quotes, comments and punctuation. */
    private function block(string $char): bool
    {
        if ("\n" === $char) {
            $this->indent = 0;
            $this->leading = true;
            $this->blockHeader = false;

            return null !== $this->blockIndent;
        }
        $this->leading = $this->leading && (' ' === $char || "\t" === $char);
        if ($this->leading) {
            ++$this->indent;
        }
        $inside = null !== $this->blockIndent && ($this->blockHeader || $this->leading || $this->indent > $this->blockIndent);
        if (!$inside) {
            $this->blockIndent = null;
        }

        return $inside;
    }
}
