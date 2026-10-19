<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Shared pinned Langium metadata normalization preserves lines and folds horizontal whitespace. */
final class MermaidMetadata
{
    public static function normalize(string $text): string
    {
        $lines = \explode("\n", \str_replace("\r", replace: '', subject: $text));
        $lines = \array_map(static fn (string $line): string => \preg_replace('/[\t ]{2,}/', replacement: ' ', subject: \trim($line)) ?? \trim($line), $lines);

        return \trim(\preg_replace('/\n{2,}/', replacement: "\n", subject: \implode("\n", $lines)) ?? '');
    }
}
