<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * What the participant roster shows changed: someone came or went, or a
 * source's link was lost or found. A FACT, for the status bar — which
 * repaints only what is marked dirty, and whose cell the roster feeds.
 */
final readonly class ParticipantsChanged {}
