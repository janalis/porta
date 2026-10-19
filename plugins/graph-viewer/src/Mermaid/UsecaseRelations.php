<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Associations and UML operators retain distinct endpoint semantics and rank lengths. */
final class UsecaseRelations
{
    /** @return array{id: ?string, arrow: string, kind: string, label: string, markdown: ?string} */
    public static function parse(UsecaseCursor $cursor): array
    {
        $id = null;
        $match = [];
        if (1 === \preg_match('/^('.UsecaseLabels::IDENTIFIER.')@/', $cursor->rest(), $match)) {
            $id = UsecaseLabels::identifier($match[1], $cursor->statement->slice($cursor->position));
            $cursor->position += \strlen($match[0]);
        }

        return \str_starts_with($cursor->rest(), '--|>') || \str_starts_with($cursor->rest(), '..>')
            ? self::semantic($cursor, $id)
            : self::association($cursor, $id);
    }

    /** @return array{id: ?string, arrow: string, kind: string, label: string, markdown: ?string} */
    private static function semantic(UsecaseCursor $cursor, ?string $id): array
    {
        if ($cursor->consume('--|>')) {
            return ['id' => $id, 'arrow' => '--', 'kind' => 'generalization', 'label' => '', 'markdown' => null];
        }
        $match = [];
        $cursor->expect('..>');
        $cursor->expect(':');
        if (1 !== \preg_match('/^(include|extend)\b/i', $cursor->rest(), $match)) {
            throw $cursor->statement->error('A use-case dependency requires include or extend', $cursor->position);
        }
        $cursor->position += \strlen($match[0]);
        $kind = \strtolower($match[1]);

        return ['id' => $id, 'arrow' => '.->', 'kind' => $kind, 'label' => $kind, 'markdown' => null];
    }

    /** @return array{id: ?string, arrow: string, kind: string, label: string, markdown: ?string} */
    private static function association(UsecaseCursor $cursor, ?string $id): array
    {
        $match = [];
        if (1 !== \preg_match('/^(?:<--+|o--|x--|--o|--x|--+>|--+)/', $cursor->rest(), $match)) {
            throw $cursor->statement->error('Expected a use-case relationship operator', $cursor->position);
        }
        $arrow = $match[0];
        $cursor->position += \strlen($arrow);
        $label = '';
        $markdown = null;
        if (\in_array($arrow, ['--', '<--', 'o--', 'x--'], strict: true)) {
            $prefix = $arrow;
            $pattern = '/^('.UsecaseLabels::QUOTED.'|(?:(?![\[\](){}"\'\r\n]|--|\.\.>|:::|@\{|<<).)+?)\s*(--+>|--o|--x|--+)/s';
            if (1 === \preg_match($pattern, $cursor->rest(), $match)) {
                $raw = \trim($match[1]);
                $quoted = \str_starts_with($raw, '"') || \str_starts_with($raw, "'") ? UsecaseLabels::quoted($raw) : null;
                $label = null === $quoted ? MermaidText::decode($raw) : $quoted['label'];
                $markdown = null !== $quoted && $quoted['markdown'] ? $quoted['raw'] : null;
                $right = $match[2];
                if ('--' !== $prefix && (\str_ends_with($right, '>') || \str_ends_with($right, 'o') || \str_ends_with($right, 'x'))) {
                    throw $cursor->statement->error('A reverse use-case association requires a markerless closing operator', $cursor->position);
                }
                if ('--' !== $right && \in_array($prefix, ['o--', 'x--'], strict: true)) {
                    throw $cursor->statement->error('Use-case circle and cross associations have fixed length', $cursor->position);
                }
                $arrow = '--' === $prefix ? $right : $prefix[0].$right;
                $cursor->position += \strlen($match[0]);
            }
        }

        return ['id' => $id, 'arrow' => $arrow, 'kind' => 'association', 'label' => $label, 'markdown' => $markdown];
    }
}
