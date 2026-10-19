<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Pinned lane identities determine inference; explicit inputs deduplicate in declaration order. */
final class EventModelingState
{
    /** @var array<int, string> */
    public array $lanes = [];
    /** @var list<int> */
    public array $frameLanes = [];
    /** @var list<array{from: int, to: int}> */
    public array $relations = [];
    /** @var array<int, int> */
    private array $next = [];

    /** @return \Generator<int, null, mixed, self> */
    public static function resolve(EventModelingDiagram $source): \Generator
    {
        $state = new self();
        foreach ($source->frames as $index => $frame) {
            $lane = $state->lane($frame['modelEntityType'], $frame['entityIdentifier'], $frame['statement']);
            $state->frameLanes[] = $lane;
            if ([] !== $frame['sourceFrames']) {
                $references = \array_fill_keys($frame['sourceFrames'], value: true);
                foreach ($state->frameLanes as $candidate => $_lane) {
                    if (\array_key_exists($source->frames[$candidate]['name'], $references)) {
                        $state->relations[] = ['from' => $candidate, 'to' => $index];
                    }
                    yield null;
                }
                continue;
            }
            if (!$frame['reset']) {
                for ($candidate = $index - 1; $candidate >= 0; --$candidate) {
                    if ($state->frameLanes[$candidate] !== $lane) {
                        $state->relations[] = ['from' => $candidate, 'to' => $index];
                        break;
                    }
                    yield null;
                }
            }
            yield null;
        }
        \ksort($state->lanes);

        return $state;
    }

    private function lane(string $role, string $entity, MermaidStatement $statement): int
    {
        $base = match ($role) {
            'ui', 'pcr', 'processor' => 0,
            'rmo', 'readmodel', 'cmd', 'command' => 100,
            default => 200,
        };
        $parts = \explode('.', $entity);
        if (2 !== \count($parts)) {
            $this->lanes[$base] = match ($base) {
                0 => 'UI/Automation',
                100 => 'Command/Read Model',
                default => 'Events',
            };

            return $base;
        }
        // The pinned database allocates qualified lanes in source order rather than reusing a namespace.
        $index = $this->next[$base] ?? ($base + 1);
        if ($index >= ($base + 100)) {
            throw $statement->error('Event modeling exceeds the qualified-lane limit');
        }
        $this->next[$base] = $index + 1;
        $this->lanes[$index] =
            match ($base) {
                0 => 'UI/A: ',
                100 => 'C/RM: ',
                default => 'Stream: ',
            }.$parts[0];

        return $index;
    }
}
