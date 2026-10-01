<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Someone {@see ParticipantJoined} announced is out of the roster: they left,
 * went quiet for too long, or the link to them stayed lost past its grace
 * period. A FACT.
 */
final readonly class ParticipantLeft implements PluginObservable
{
    public function __construct(
        public string $id,
        public string $name,
        public string $via,
    ) {}
}
