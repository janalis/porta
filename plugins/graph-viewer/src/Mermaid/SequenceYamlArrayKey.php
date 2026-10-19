<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** js-yaml stringifies flat arrays, rejects nested arrays and never walks map items. */
final class SequenceYamlArrayKey
{
    /**
     * @param array<int, array{int, int, bool}|null> $bindings
     * @param array<int, array{int, int, bool}> $properties
     * @return array{string, bool} string key and whether the definition is complete at this use
     */
    public static function read(string $yaml, int $start, int $use, array $bindings, array $properties, MermaidStatement $statement): array
    {
        $items = SequenceYamlItems::read($yaml, $start);
        $parts = [];
        $bytes = 0;
        foreach ($items as [$first, $end, $indent]) {
            if ($end > $use) {
                continue;
            }
            [, $offset] = SequenceYamlNode::read($yaml, $first, $indent, ownLine: true);
            if ($offset >= $end && '[' === $yaml[$start]) {
                continue;
            }
            $definition = $properties[$offset] ?? [$offset, $indent, false];
            if ('*' === ($yaml[$offset] ?? '')) {
                $definition = $bindings[$offset] ?? null;
                if (null === $definition) {
                    throw $statement->error('Invalid sequence participant YAML configuration');
                }
            }
            $value = $offset >= $end
                ? ''
                : SequenceYamlScalar::elementValue($yaml, $definition[0], $definition[1], $definition[2], $statement);
            if (null === $value) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
            $value = MermaidUtf16::pairs($value);
            $bytes += \strlen($value) + ([] === $parts ? 0 : 1);
            if ($bytes > 262_144) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
            $parts[] = $value;
        }

        return [\implode(',', $parts), $items->getReturn() <= $use];
    }
}
