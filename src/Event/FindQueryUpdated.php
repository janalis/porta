<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The find bar's query or options changed.
 *
 * Carries nothing: the match mirror lives on the bar's own FindTarget, so the
 * only thing left to do is repaint.
 */
final readonly class FindQueryUpdated {}
