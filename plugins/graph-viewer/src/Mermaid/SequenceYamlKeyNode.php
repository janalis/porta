<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Original key-node range and properties, without decoding or expanding its graph. */
final readonly class SequenceYamlKeyNode
{
    public function __construct(
        public int $body,
        public int $end,
        public string $properties,
        public ?string $anchor,
    ) {}

    public static function read(string $yaml, int $start, int $flowIndent, MermaidStatement $statement): self
    {
        return self::node($yaml, $start, $flowIndent, $flowIndent, $statement);
    }

    public static function block(string $yaml, int $start, int $parentIndent, MermaidStatement $statement): self
    {
        return self::node($yaml, $start, flowIndent: null, parentIndent: $parentIndent, statement: $statement);
    }

    private static function node(string $yaml, int $start, ?int $flowIndent, int $parentIndent, MermaidStatement $statement): self
    {
        $offset = $start;
        $properties = [];
        $anchor = null;
        $seen = [];
        $match = [];
        while (1 === \preg_match('/\G(?:&[^\s,\[\]{}]+|!<[^>\r\n]*>|![^\s,\[\]{}]*)/', $yaml, $match, offset: $offset)) {
            $token = $match[0];
            $end = $offset + \strlen($token);
            if ('!' === $token[0] && !\str_starts_with($token, '!<') && $end < \strlen($yaml) && !\ctype_space($yaml[$end])) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
            $isAnchor = \str_starts_with($token, '&');
            $kind = $isAnchor ? 'anchor' : 'tag';
            if (\array_key_exists($kind, $seen)) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
            $seen[$kind] = true;
            $properties[] = $token;
            if (null === $anchor && \str_starts_with($token, '&')) {
                $anchor = \substr($token, offset: 1);
            }
            $offset = self::spaces($yaml, $end);
        }
        $char = $yaml[$offset] ?? '';
        if ([] !== $properties && '*' === $char) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        $empty = false;
        if ([] !== $properties && false !== \strpbrk(\substr($yaml, $start, $offset - $start), characters: "\r\n")) {
            $line = $offset;
            while ($line > 0 && "\n" !== $yaml[$line - 1]) {
                --$line;
            }
            if (\strspn($yaml, characters: ' ', offset: $line) <= $parentIndent) {
                if ('' !== $char && !\str_contains(':,]}', $char)) {
                    throw $statement->error('Invalid sequence participant YAML configuration');
                }
                $empty = true;
            }
        }
        $end = match (true) {
            $empty => $offset,
            \in_array($char, ['[', '{'], strict: true) => self::collectionEnd($yaml, $offset),
            \in_array($char, ['"', "'"], strict: true) => self::quotedEnd($yaml, $offset),
            \in_array($char, ['|', '>'], strict: true) => SequenceYamlScalar::tagged($yaml, $offset, $statement, blockIndent: $parentIndent)[1],
            null === $flowIndent => SequenceYamlScalar::blockKey($yaml, $offset, $statement)[1] ?? $offset,
            default => SequenceYamlScalar::tagged($yaml, $offset, $statement, flowIndent: $flowIndent, blockIndent: $parentIndent)[1],
        };

        return new self($offset, $end, [] === $properties ? '' : \implode(' ', $properties).' ', $anchor);
    }

    private static function spaces(string $yaml, int $offset): int
    {
        $length = \strlen($yaml);
        while ($offset < $length) {
            if (\ctype_space($yaml[$offset])) {
                ++$offset;
                continue;
            }
            if ('#' !== $yaml[$offset]) {
                break;
            }
            $newline = \strpos($yaml, needle: "\n", offset: $offset);
            $offset = false === $newline ? $length : $newline + 1;
        }

        return $offset;
    }

    private static function collectionEnd(string $yaml, int $start): int
    {
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $depth = 0;
        $length = \strlen($yaml);
        for ($offset = $start; $offset < $length; ++$offset) {
            $char = $yaml[$offset];
            if (!$text->advance($char)) {
                continue;
            }
            $depth += match ($char) {
                '[', '{' => 1,
                ']', '}' => -1,
                default => 0,
            };
            if (0 === $depth) {
                return $offset + 1;
            }
        }

        return $length;
    }

    private static function quotedEnd(string $yaml, int $start): int
    {
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $offset = $start;
        $length = \strlen($yaml);
        while ($offset < $length && !$text->advance($yaml[$offset])) {
            ++$offset;
        }

        return $offset;
    }
}
