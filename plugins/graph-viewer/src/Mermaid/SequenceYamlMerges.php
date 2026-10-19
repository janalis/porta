<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** JSON_SCHEMA treats merge spellings as ordinary keys, unlike Symfony Yaml. */
final class SequenceYamlMerges
{
    /** @return array{string, ?string} shielded YAML and a collision-free restoration marker */
    public static function mask(string $yaml): array
    {
        if (!\str_contains($yaml, '<<')) {
            return [$yaml, null];
        }
        $marker = SequenceYamlSymbols::marker($yaml, stem: '__porta_merge_');

        // Replace in all contexts, then restore only the two scalar fields that
        // the renderer consumes. This also shields nested and explicit keys,
        // without interpreting quotes, block indentation or ignored collections.
        return [\str_replace('<<', $marker, $yaml), $marker];
    }
}
