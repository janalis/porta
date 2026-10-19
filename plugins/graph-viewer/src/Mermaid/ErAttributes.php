<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Attribute tokens are whitespace-separated, with literal backtick words and quoted comments. */
final class ErAttributes
{
    private int $count = 0;

    /** @return list<array{text: string, origin: int, cells: list<string>}> the prose row, and its type, name, keys and comment cells */
    public function parse(string $body, MermaidStatement $statement, int $start): array
    {
        $word = '(?:`[^`]+`|[\p{L}_*][\p{L}\p{N}_*.,()\[\]-]*(?:~[^~]*~[\p{L}\p{N}_*.]*)?)';
        $pattern =
            '~\G('
            .\str_replace('~', replace: '\\~', subject: $word)
            .'\??)\s+('
            .\str_replace('~', replace: '\\~', subject: $word)
            .')(?:\s+((?:PK|FK|UK)(?:\s*,\s*(?:PK|FK|UK))*))?(?:\s*("[^"]*+"))?~iu';
        $offset = 0;
        $attributes = [];
        while ($offset < \strlen($body) && '' !== \trim(\substr($body, $offset))) {
            $match = [];
            if (1 === \preg_match('/\G(?:\s+|%%[^\r\n]*)/', $body, $match, offset: $offset)) {
                $offset += \strlen($match[0]);
                continue;
            }
            if (1 !== \preg_match($pattern, $body, $match, offset: $offset)) {
                throw $statement->error('Expected ER attribute type, name, optional keys and comment', $start + $offset);
            }
            if (++$this->count > MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the ER attribute limit', $start + $offset);
            }
            $type = \str_replace('`', replace: '', subject: $match[1]);
            $name = \trim($match[2], characters: '`');
            if (\in_array(\strtoupper($type), ['PK', 'FK', 'UK'], strict: true) || \in_array(\strtoupper($name), ['PK', 'FK', 'UK'], strict: true)) {
                throw $statement->error('ER attribute keys must follow a type and name', $start + $offset);
            }
            $key = \strtoupper(\preg_replace('/\s+/', replacement: '', subject: $match[3] ?? '') ?? '');
            $note = '' === ($match[4] ?? '') ? '' : \substr($match[4], offset: 1, length: -1);
            $keys = '' === $key ? '' : ' ['.$key.']';
            $comment = '' === $note ? '' : ' — '.$note;
            $prefix = \substr($statement->text, offset: 0, length: $start + $offset);
            $attributes[] = [
                'text' => MermaidText::decode($type.' '.$name.$keys.$comment),
                'origin' => $statement->line + \substr_count($prefix, needle: "\n"),
                // A browser's table cell runs a comment's line breaks together.
                'cells' => \array_map(static fn (string $cell): string => \preg_replace('/\s*\R\s*/', replacement: ' ', subject: MermaidText::decode($cell)) ?? $cell, [$type, $name, $key, $note]),
            ];
            $offset += \strlen($match[0]);
        }

        return $attributes;
    }
}
