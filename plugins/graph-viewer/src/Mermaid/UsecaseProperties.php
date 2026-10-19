<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Only typed, role-specific metadata enters the committed visual model. */
final class UsecaseProperties
{
    public static function property(UsecaseElement $element, string $key, string|bool $value, MermaidStatement $statement): void
    {
        $valid = match ($element->kind) {
            'actor' => match ($key) {
                'type' => \in_array($value, ['normal', 'hollow', 'awesome'], strict: true),
                'icon' => \is_string($value),
                'business' => \is_bool($value),
                default => false,
            },
            'usecase' => 'business' === $key && \is_bool($value),
            'boundary' => 'type' === $key && \in_array($value, ['rect', 'package'], strict: true),
            'edge' => ('animate' === $key && \is_bool($value)) || ('animation' === $key && \in_array($value, ['fast', 'slow'], strict: true)),
            default => false,
        };
        if (!$valid) {
            throw $statement->error('Invalid '.$element->kind.' metadata property '.$key);
        }
    }

    public static function validate(UsecaseElement $element): void
    {
        $icon = $element->metadata['icon'] ?? '';
        $type = '' !== $icon ? 'icon' : $element->metadata['type'] ?? 'normal';
        if ('actor' === $element->kind) {
            if ('' !== $icon && 'normal' !== ($element->metadata['type'] ?? 'normal')) {
                throw $element->statement->error('A use-case actor cannot combine an icon with a non-normal type');
            }
            if (true === ($element->metadata['business'] ?? false) && !\in_array($type, ['normal', 'hollow'], strict: true)) {
                throw $element->statement->error('Business actors require normal or hollow geometry');
            }
        }
        if ('usecase' === $element->kind && 'rect' === $element->shape && true === ($element->metadata['business'] ?? false)) {
            throw $element->statement->error('A rectangular use case cannot be a business use case');
        }
    }

    public static function relationship(UsecaseRelation $relation, UsecaseElement $from, UsecaseElement $to): void
    {
        $kind = $relation->kind;
        $types = [$from->kind, $to->kind];
        if (\array_intersect($types, ['boundary', 'edge'])) {
            throw $relation->statement->error('Use-case relationships cannot target boundaries or edges');
        }
        $valid = match ($kind) {
            'include', 'extend' => ['usecase', 'usecase'] === $types,
            'generalization' => $types[0] === $types[1] && \in_array($types[0], ['actor', 'usecase'], strict: true),
            default => !\in_array('json', $types, strict: true) || 1 === \preg_match('/^(?:--+>|<--+|--+)$/', $relation->arrow),
        };
        if (!$valid) {
            throw $relation->statement->error('Invalid use-case relationship endpoint kinds');
        }
    }
}
