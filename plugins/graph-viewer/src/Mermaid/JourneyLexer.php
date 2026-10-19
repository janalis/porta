<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Journey names are literal text; metadata blocks own their punctuation and newlines. */
final readonly class JourneyLexer
{
    private const array PATTERNS = [
        '/\Gjourney\b/i',
        '/\GaccDescr\s*\{[^}]*\}/i',
        '/\G(?:accTitle|accDescr)\s*:\s*[^\n]*/i',
        '/\Gtitle\s[^#\n;]+/i',
        '/\Gsection\s[^#:\n;]+/i',
        '/\G[^#:\n;]+:[^#\n;]+/',
    ];

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
        while ($offset < \strlen($this->source)) {
            $match = [];
            if (1 === \preg_match('/\G(?:\s+|%%(?!\{)[^\n]*|[^}]%%[^\n]*|#[^\n]*)/u', $this->source, $match, offset: $offset)) {
                $this->advance($match[0], $offset, $line, $column);
                continue;
            }
            $text = $this->token($offset);
            $statement = new MermaidStatement($text, $offset, $line, $column);
            if (\strlen($text) > MermaidSource::MAX_STATEMENT) {
                throw $statement->error('Statement exceeds the size limit');
            }
            yield $statement;
            $this->advance($text, $offset, $line, $column);
        }
    }

    private function token(int $offset): string
    {
        $match = [];
        foreach (self::PATTERNS as $pattern) {
            if (1 === \preg_match($pattern, $this->source, $match, offset: $offset)) {
                return $match[0];
            }
        }
        $end = \strpos($this->source, needle: "\n", offset: $offset);

        return \substr($this->source, $offset, false === $end ? null : $end - $offset);
    }

    private function advance(string $text, int &$offset, int &$line, int &$column): void
    {
        $offset += \strlen($text);
        $line += \substr_count($text, needle: "\n");
        $last = \strrpos($text, needle: "\n");
        $column = false === $last ? $column + \mb_strlen($text) : \mb_strlen(\substr($text, $last + 1));
    }
}
