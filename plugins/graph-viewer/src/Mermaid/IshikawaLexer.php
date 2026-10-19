<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Each non-comment physical line is one label: quotes and separators have no special meaning. */
final readonly class IshikawaLexer
{
    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        MermaidSource::validate($this->source);
        $offset = 0;
        foreach (\explode("\n", $this->source) as $line => $raw) {
            $text = \ltrim($raw, characters: " \t\r");
            $indent = \strlen($raw) - \strlen($text);
            $start = $offset + $indent;
            $offset += \strlen($raw) + 1;
            if ('' === $text || \str_starts_with($text, '%%')) {
                continue;
            }
            $statement = new MermaidStatement(\rtrim($text), $start, $line, $indent);
            if (\strlen($text) > MermaidSource::MAX_STATEMENT) {
                throw $statement->error('Statement exceeds the size limit');
            }
            yield $statement;
        }
    }
}
