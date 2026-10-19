<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Annotations are inert data; descriptions consume the rest of their line. */
final class TreeViewLabels
{
    /** @return array{label: string, class: string, icon: ?string, description: string} */
    public static function parse(MermaidStatement $statement): array
    {
        [$label, $tail] = self::name($statement);
        $match = [];
        $result = ['label' => $label, 'class' => '', 'icon' => null, 'description' => ''];
        while ('' !== $tail) {
            if (1 === \preg_match('/^[\t ]+(?::::[\t ]*([A-Za-z_][\w-]*)|icon\(([\w-]*(?::[\w-]+)?)\))/', $tail, $match)) {
                $class = $match[1] ?? '';
                $key = '' !== $class ? 'class' : 'icon';
                $icon = '' === ($match[2] ?? '') ? 'none' : $match[2];
                $result[$key] = '' !== $class ? $class : $icon;
                $tail = \substr($tail, \strlen($match[0]));
                continue;
            }
            if (1 === \preg_match('/^[\t ]+##(.*)$/s', $tail, $match)) {
                $result['description'] = \trim($match[1]);
                break;
            }
            if ('' === \trim($tail) || \str_starts_with(\ltrim($tail), '%%')) {
                break;
            }
            throw $statement->error('Invalid tree-view annotation');
        }

        return $result;
    }

    /** @return array{string, string} */
    private static function name(MermaidStatement $statement): array
    {
        $match = [];
        if (1 === \preg_match('/^("[^"]*"|\'[^\']*\')(.*)$/s', $statement->text, $match)) {
            return [\substr($match[1], offset: 1, length: -1), $match[2]];
        }
        $parts = \preg_split('/(?=[\t ]+(?::::[\t ]*[A-Za-z_]|icon\(|##|%%))/', $statement->text, limit: 2);
        $label = \rtrim($parts[0] ?? '');
        if ('' === $label || 1 === \preg_match('/^(?::::|icon\(|##|["\'])/', $label)) {
            throw $statement->error('Expected a tree-view label');
        }

        return [$label, $parts[1] ?? ''];
    }
}
