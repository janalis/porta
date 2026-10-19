<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Project participant visibility without changing the parsed chronology or source model. */
final class SequenceVisibility
{
    /** @return \Generator<int, null, mixed, SequenceDiagram> */
    public static function apply(SequenceDiagram $source, MermaidConfiguration $configuration): \Generator
    {
        if (true !== $configuration->hideUnusedParticipants) {
            return $source;
        }
        $used = [];
        foreach ($source->events as $event) {
            $used[$event['from']] = true;
            $used[$event['to']] = true;
            yield null;
        }
        $visible = clone $source;
        $visible->participants = \array_intersect_key($source->participants, $used);
        $visible->menus = \array_intersect_key($source->menus, $visible->participants);
        $visible->participantTypes = \array_intersect_key($source->participantTypes, $visible->participants);
        $visible->lifetimes = \array_intersect_key($source->lifetimes, $visible->participants);
        $visible->groups = [];
        $visible->memberships = [];
        foreach ($source->groups as $group) {
            $actors = [];
            foreach ($group['actors'] as $actor) {
                if (\array_key_exists($actor, $visible->participants)) {
                    $actors[] = $actor;
                    $visible->memberships[$actor] = \count($visible->groups);
                }
                yield null;
            }
            if ([] !== $actors) {
                $visible->groups[] = ['label' => $group['label'], 'actors' => $actors, 'origin' => $group['origin']];
            }
            yield null;
        }

        return $visible;
    }
}
