<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Located pattern scanning shared by radar and Cynefin; each family owns its declaration grammar. */
final readonly class MermaidPatternLexer
{
    /** @param list<string> $patterns */
    public function __construct(
        private string $source,
        private array $patterns,
        private string $error,
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
            if (1 === \preg_match('/\G(?:\s+|%%[^\r\n]*)/u', $this->source, $match, offset: $offset)) {
                self::advance($match[0], $offset, $line, $column);
                continue;
            }
            $text = $this->token($offset, $line, $column);
            $statement = new MermaidStatement($text, $offset, $line, $column);
            if (\strlen($text) > MermaidSource::MAX_STATEMENT) {
                throw $statement->error('Statement exceeds the size limit');
            }
            yield $statement;
            self::advance($text, $offset, $line, $column);
        }
    }

    private function token(int $offset, int $line, int $column): string
    {
        $match = [];
        foreach ($this->patterns as $pattern) {
            if (1 === \preg_match($pattern, $this->source, $match, offset: $offset)) {
                return \rtrim($match[0]);
            }
        }
        throw new MermaidSyntaxError($this->error, $offset, $line, $column);
    }

    private static function advance(string $text, int &$offset, int &$line, int &$column): void
    {
        $offset += \strlen($text);
        $line += \substr_count($text, needle: "\n");
        $last = \strrpos($text, needle: "\n");
        $column = false === $last ? $column + \mb_strlen($text) : \mb_strlen(\substr($text, $last + 1));
    }
}
