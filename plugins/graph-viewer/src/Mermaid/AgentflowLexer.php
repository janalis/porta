<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Metadata is a separate lexical action, preserving its authored source position. */
final readonly class AgentflowLexer
{
    public function __construct(
        private string $source,
    ) {}

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        foreach (new MermaidLexer($this->source)->statements() as $statement) {
            $quote = new MermaidQuotedText();
            $pipe = false;
            $start = 0;
            $length = \strlen($statement->text);
            for ($offset = 0; $offset < $length; ++$offset) {
                $char = $statement->text[$offset];
                $quote->advance($char);
                if ($quote->quoted) {
                    continue;
                }
                if ('|' === $char) {
                    $pipe = !$pipe;
                }
                if ($pipe || '@' !== $char || '{' !== ($statement->text[$offset + 1] ?? '')) {
                    continue;
                }
                if ($offset > $start) {
                    yield $statement->slice($start, $offset - $start);
                }
                $end = $this->metadataEnd($statement, $offset);
                yield $statement->slice($offset, $end - $offset);
                $offset = $end - 1;
                $start = $end;
            }
            if ($start < $length) {
                yield $statement->slice($start);
            }
        }
    }

    private function metadataEnd(MermaidStatement $statement, int $offset): int
    {
        $text = new MermaidConfigurationText();
        $depth = 0;
        $length = \strlen($statement->text);
        for ($index = $offset + 1; $index < $length; ++$index) {
            $char = $statement->text[$index];
            if (!$text->advance($char)) {
                continue;
            }
            if ('{' === $char) {
                ++$depth;
                continue;
            }
            if ('}' === $char && 0 === --$depth) {
                break;
            }
        }

        return $index + 1;
    }
}
