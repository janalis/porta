<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Original block key node, its extent and the indentation needed by a shadow value. */
final readonly class SequenceYamlBlockKey
{
    /**
     * @param non-negative-int $column
     * @param non-negative-int|null $blockColumn
     */
    public function __construct(
        public int $start,
        public SequenceYamlKeyNode $node,
        public int $end,
        public int $column,
        public ?int $blockColumn,
        public bool $explicit,
    ) {}

    /** @return non-negative-int */
    public function padding(): int
    {
        return ($this->blockColumn ?? 0) + ($this->column * ($this->explicit ? 1 : 2));
    }

    public static function read(string $yaml, int $offset, int $lineStart, int $boundary, MermaidStatement $statement): ?self
    {
        $char = $yaml[$offset];
        $explicit = '?' === $char && \ctype_space($yaml[$offset + 1] ?? "\n");
        if (!$explicit && !\in_array($char, ['[', '{'], strict: true)) {
            return null;
        }
        $column = \max(0, $offset - $lineStart);
        $end = $explicit ? $boundary : \strlen($yaml);
        $start = $offset + ($explicit ? 1 : 0);
        while (\in_array($yaml[$start] ?? '', [' ', "\t", "\r", "\n"], strict: true)) {
            ++$start;
        }
        [, $start] = SequenceYamlNode::read($yaml, $start, $column, ownLine: false);
        $node = $start >= $end
            ? new SequenceYamlKeyNode($end, $end, properties: '', anchor: null)
            : SequenceYamlKeyNode::block($yaml, $start, $column, $statement);

        return $explicit
            ? self::explicit($yaml, $offset, $node, $end, $column, $statement)
            : self::implicit($yaml, $offset, $node, $column);
    }

    public static function property(string $yaml, int $offset, int $lineStart, MermaidStatement $statement): ?self
    {
        $column = \max(0, $offset - $lineStart);
        $node = SequenceYamlKeyNode::block($yaml, $offset, $column, $statement);
        if (false !== \strpbrk(\substr($yaml, $offset, $node->body - $offset), characters: "\r\n")) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }

        return self::implicit($yaml, $offset, $node, $column);
    }

    public static function validateValue(string $yaml, int $offset, MermaidStatement $statement): void
    {
        $end = \strpos($yaml, needle: "\n", offset: $offset);
        $line = \substr($yaml, $offset, false === $end ? null : $end - $offset);
        $match = [];
        \preg_match('/\A(?:(?:&[^\s,\[\]{}]+|!<[^>\r\n]*>|![^\s,\[\]{}]*)[\t ]*)+(.*)$/D', $line, $match);
        $body = $match[1] ?? '';
        if ('' === $body || \str_contains('#|>', $body[0])) {
            return;
        }
        $node = SequenceYamlKeyNode::block($line, start: 0, parentIndent: 0, statement: $statement);
        if (self::compact($line, $node, column: 0)) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
    }

    /** @param non-negative-int $column */
    private static function implicit(string $yaml, int $offset, SequenceYamlKeyNode $node, int $column): ?self
    {
        $colon = $node->end + \strspn($yaml, characters: " \t", offset: $node->end);
        if (':' !== ($yaml[$colon] ?? '') || !\ctype_space($yaml[$colon + 1] ?? "\n") || false !== \strpbrk(\substr($yaml, $offset, $node->end - $offset), characters: "\r\n")) {
            return null;
        }

        return new self($offset, $node, $colon, $column, null, explicit: false);
    }

    /** @param non-negative-int $column */
    private static function explicit(string $yaml, int $offset, SequenceYamlKeyNode $node, int $end, int $column, MermaidStatement $statement): ?self
    {
        if ('*' === ($yaml[$node->body] ?? '')) {
            return null;
        }
        $compact = $node->body < $end && self::compact($yaml, $node, $column);
        $bodyLine = $node->body;
        while ($bodyLine > 0 && "\n" !== $yaml[$bodyLine - 1]) {
            --$bodyLine;
        }
        if ($compact && false !== \strpbrk(\substr($yaml, $bodyLine, $node->body - $bodyLine), characters: '!&')) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }

        return new self($offset, $node, $end, $column, $compact ? \max(0, $node->body - $bodyLine) : null, explicit: true);
    }

    private static function compact(string $yaml, SequenceYamlKeyNode $node, int $column): bool
    {
        [$kind] = SequenceYamlNode::read($yaml, $node->body, $column, ownLine: false);
        $char = $yaml[$node->body] ?? '';
        $colon = $node->end + \strspn($yaml, characters: " \t", offset: $node->end);

        return (
            ('seq' === $kind && '[' !== $char)
            || ('map' === $kind && '{' !== $char)
            || (':' === ($yaml[$colon] ?? '') && \ctype_space($yaml[$colon + 1] ?? "\n") && false === \strpbrk(\substr($yaml, $node->body, $node->end - $node->body), characters: "\r\n"))
        );
    }
}
