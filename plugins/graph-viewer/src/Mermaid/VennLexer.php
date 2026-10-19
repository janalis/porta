<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Pinned case-insensitive tokens preserve newlines and indentation without losing quoted labels. */
final readonly class VennLexer
{
    public const string ID = '[A-Za-z_][A-Za-z0-9_-]*';
    public const string NUMBER = '[+-]?(?:\d+(?:\.\d+)?|\.\d+)';

    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        MermaidSource::validate($this->source);
        $offset = 0;
        $line = 0;
        $column = 0;
        $length = \strlen($this->source);
        $count = 0;
        while ($offset < $length) {
            $statement = new MermaidStatement('', $offset, $line, $column);
            $match = [];
            $hidden = 1 === \preg_match('/\G(?:[\t ]+|%%[^\r\n]*|[^}\r\n]%%[^\r\n]*)/', $this->source, $match, offset: $offset);
            if (
                !$hidden
                && 1 !== \preg_match(
                    '/\G(?:[\r\n]+|title\s[^#\n;]+|venn-beta|set|union|text|style|\["[^"]*"\]|\[[^\]"]+\]|'
                    .self::NUMBER
                    .'|\#[0-9a-f]{3,8}|rgba\(\s*[0-9.]+\s*,\s*[0-9.]+\s*,\s*[0-9.]+\s*,\s*[0-9.]+\s*\)|rgb\(\s*[0-9.]+\s*,\s*[0-9.]+\s*,\s*[0-9.]+\s*\)|'
                    .self::ID
                    .'|"[^"]*"|[,:])/i',
                    $this->source,
                    $match,
                    offset: $offset,
                )
            ) {
                throw $statement->error('Unexpected Venn token');
            }
            $text = $match[0];
            if (++$count > 131_072 || \strlen($text) > MermaidSource::MAX_STATEMENT) {
                throw $statement->error('Venn token or work limit exceeded');
            }
            $newline = 1 === \preg_match('/^[\r\n]+$/', $text);
            yield new MermaidStatement(
                match (true) {
                    $hidden => '',
                    $newline => "\n",
                    default => $text,
                },
                $offset,
                $line,
                $column,
            );
            $breaks = \preg_match_all('/\r\n|\r|\n/', $text);
            $last = \strrpos($text, needle: "\n");
            $column = false === $last ? $column + \mb_strlen($text) : \mb_strlen(\substr($text, $last + 1));
            $line += (int) $breaks;
            $offset += \strlen($text);
        }
    }
}
