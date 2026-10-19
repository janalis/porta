<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Participant metadata is readable in terminal panels instead of browser popup menus. */
final class SequenceMenus
{
    private int $count = 0;

    public function __construct(
        private readonly SequenceDiagram $diagram,
    ) {}

    public function accept(string $kind, string $actor, string $text, MermaidStatement $statement): void
    {
        $text = \trim($text);
        if ('details' === $kind) {
            if ('' === $text) {
                throw $statement->error('Sequence details requires a document element reference');
            }
            $this->store($actor, 'details', 'reference', MermaidText::decode($text), $statement);

            return;
        }
        if ('link' === $kind) {
            $parts = \explode('@', $text, limit: 2);
            if (2 !== \count($parts) || '' === \trim($parts[0]) || '' === \trim($parts[1])) {
                throw $statement->error('Sequence link requires a label @ URL');
            }
            $this->store($actor, 'links', MermaidText::decode(\trim($parts[0])), MermaidText::decode(\trim($parts[1])), $statement);

            return;
        }
        $object = self::object($text, $statement);
        // @mago-expect analysis:mixed-assignment -- Link values are narrowed below; property values remain serialized JSON.
        foreach (\get_object_vars($object) as $label => $value) {
            if ('links' === $kind && !\is_string($value)) {
                throw $statement->error('Sequence link URLs must be strings');
            }
            $this->store($actor, $kind, MermaidText::decode((string) $label), self::display($value, $statement), $statement);
        }
    }

    private static function display(mixed $value, MermaidStatement $statement): string
    {
        try {
            return \is_string($value)
                ? MermaidText::decode($value)
                : \json_encode($value, flags: \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $statement->error('Sequence property exceeds the numeric value limit');
        }
    }

    private static function object(string $text, MermaidStatement $statement): \stdClass
    {
        try {
            // @mago-expect analysis:mixed-assignment -- Decoded JSON must be an object below.
            $object = \json_decode($text, associative: false, depth: 128, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $statement->error('Invalid sequence menu JSON');
        }
        if (!$object instanceof \stdClass) {
            throw $statement->error('Sequence menu JSON must be an object');
        }

        return $object;
    }

    private function store(string $actor, string $kind, string $label, string $value, MermaidStatement $statement): void
    {
        $this->diagram->menus[$actor] ??= [];
        $key = $kind.':'.$label;
        if (!\array_key_exists($key, $this->diagram->menus[$actor])) {
            if ($this->count >= MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the sequence menu item limit');
            }
            ++$this->count;
        }
        $this->diagram->menus[$actor][$key] = ['kind' => $kind, 'label' => $label, 'value' => $value, 'origin' => $statement->line];
    }
}
