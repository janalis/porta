<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Shared XY list boundaries and finite decimal parsing, independent of axis orientation. */
final class XyValues
{
    public const string NUMBER = '[+-]?(?:\d+(?:\.\d+)?|\.\d+)';

    public static function find(string $text, string $needle): ?int
    {
        $quote = new MermaidQuotedText();
        $length = \strlen($text);
        for ($index = 0; $index < $length; ++$index) {
            $quote->advance($text[$index]);
            if (!$quote->quoted && 0 === \substr_compare($text, $needle, $index, \strlen($needle))) {
                return $index;
            }
        }

        return null;
    }

    /** @return ?array{string, string} */
    public static function array(string $text, MermaidStatement $statement): ?array
    {
        $start = self::find($text, '[');
        if (null === $start) {
            return null;
        }
        $text = \rtrim($text);
        if (!\str_ends_with($text, ']')) {
            throw $statement->error('XY array must end with a closing bracket');
        }

        return [\trim(\substr($text, offset: 0, length: $start)), \substr($text, $start + 1, length: -1)];
    }

    /** @return list<string> */
    public static function items(string $text, MermaidStatement $statement): array
    {
        $quote = new MermaidQuotedText();
        $items = [];
        $start = 0;
        $length = \strlen($text);
        for ($index = 0; $index <= $length; ++$index) {
            if ($index < $length) {
                $quote->advance($text[$index]);
            }
            if ($index < $length && ($quote->quoted || ',' !== $text[$index])) {
                continue;
            }
            if (\count($items) >= MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the XY item limit');
            }
            $item = \trim(\substr($text, $start, $index - $start));
            if ('' === $item) {
                throw $statement->error('XY arrays require nonempty items');
            }
            $items[] = $item;
            $start = $index + 1;
        }

        return $items;
    }

    public static function number(string $text, MermaidStatement $statement): float
    {
        if (!\is_numeric($text) || 1 !== \preg_match('/^'.self::NUMBER.'$/D', $text)) {
            throw $statement->error('Expected an XY decimal number');
        }
        $number = (float) $text;
        if (!\is_finite($number)) {
            throw $statement->error('XY values must be finite');
        }

        return $number;
    }
}
