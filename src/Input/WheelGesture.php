<?php

declare(strict_types=1);

namespace Porta\Input;

/**
 * A wheel notch, with its axis and its already-resolved step.
 *
 * The step is computed ONCE by core ({@see WheelStep}) rather than by each
 * target. That is what let the two vertical-scroll verbs merge: git's took the
 * step from its caller and settings' computed its own, so a shared method could
 * not be typed — the difference was never about scrolling, only about who did
 * the arithmetic.
 */
final readonly class WheelGesture
{
    public function __construct(
        public MouseEvent $mouse,
        public WheelAxis $axis,
        public int $step,
    ) {}
}
