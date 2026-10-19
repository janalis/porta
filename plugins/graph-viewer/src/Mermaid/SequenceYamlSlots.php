<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Value/key positions outside quoted, commented and block-scalar content. */
final class SequenceYamlSlots
{
    public private(set) ?int $first = null;
    public private(set) int $indent = 0;
    public private(set) int $depth = 0;
    public private(set) string $collection = '';
    public private(set) string $previous = '';
    private string $token = '';
    /** @var list<string> */
    private array $collections = [];
    private bool $property = false;
    public private(set) ?int $question = null;
    public private(set) int $questionIndent = 0;
    public private(set) bool $valueNode = false;
    public private(set) bool $keyNode = false;
    private ?int $pendingQuestion = null;
    private int $pendingIndent = 0;
    private bool $prefix = true;
    private bool $valueLine = false;
    private int $documentUntil = 0;

    // @mago-expect lint:no-boolean-flag-parameter -- These are lexical shielding/property facts from the shared YAML scanner.
    public function advance(string $yaml, int $offset, bool $structural, bool $anchor, bool $quote, bool $tag): void
    {
        $char = $yaml[$offset];
        $space = \str_contains(" \t\r\n", $char);
        if ("\n" === $char) {
            $this->first = null;
            $this->indent = 0;
            $this->prefix = true;
            $this->valueLine = false;
        }
        if (null === $this->first && !$space) {
            $this->first = $offset;
        }
        if (null === $this->first && \in_array($char, [' ', "\t"], strict: true)) {
            ++$this->indent;
        }
        $this->previous = $this->token;
        if (
            $offset < $this->documentUntil
            || ($structural && 0 === $this->depth && $this->first === $offset && '-' === $char && \ctype_space($yaml[$offset + 3] ?? "\n") && '---' === \substr($yaml, $offset, length: 3))
        ) {
            $this->documentUntil = \max($this->documentUntil, $offset + ('-' === $char && $offset >= $this->documentUntil ? 3 : 0));
            $this->token = '';
            $this->prefix = true;
            $this->keyNode = false;

            return;
        }
        if ($anchor || $tag) {
            $this->property = true;
        }
        $head = $this->prefix || '' === $this->previous || \str_contains('[{,:', $this->previous);
        if ($structural && $head && \str_contains('[{', $char)) {
            $this->collections[] = $char;
        }
        if ($structural && [] !== $this->collections && \str_contains(']}', $char)) {
            \array_pop($this->collections);
        }
        $this->depth = \count($this->collections);
        $this->collection = \array_last($this->collections) ?? '';
        $indicator = \in_array($char, ['-', '?'], strict: true) && \str_contains(" \t\r\n", $yaml[$offset + 1] ?? "\n");
        $keyHead = 0 === $this->depth ? $this->prefix : \str_contains('[{,', $this->previous);
        $question = $structural && $keyHead && '?' === $char && $indicator;
        $this->question = ($structural && !$space) || $anchor ? $this->pendingQuestion : null;
        $this->questionIndent = null === $this->question ? 0 : $this->pendingIndent;
        if ($structural && !$space && !$tag) {
            $this->pendingQuestion = $question ? $offset : null;
            if ($question) {
                $this->pendingIndent = $this->indent;
            }
        }
        $this->valueNode = $this->depth > 0
            ? \str_contains('[{,:', $this->previous) || null !== $this->question
            : $this->prefix || $this->valueLine;
        $this->keyNode = $this->prefix;
        if (!$space && !$this->property) {
            $this->prefix = $this->prefix && $indicator;
        }
        if ($structural && $space) {
            $this->property = false;
        }
        if ($quote || ($structural && !$space && !$tag)) {
            $this->token = $quote ? '"' : $char;
        }
        if ($structural && ':' === $char && \str_contains(" \t\r\n", $yaml[$offset + 1] ?? "\n")) {
            $this->valueLine = true;
        }
    }
}
