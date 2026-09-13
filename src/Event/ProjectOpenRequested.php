<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Close this project and reopen the IDE on another one.
 *
 * A command, so exactly one listener performs it ({@see \Porta\Ide}). It exists
 * because the thing that has to act is not a service at all: a project root is
 * fixed when {@see \Porta\Ide} builds its container, so every service in the
 * running IDE belongs to the project being left. Only the object that OWNS the
 * container can answer, and the only way a service reaches it is a fact on the
 * bus. Scalars only, per the bus's rule.
 */
final readonly class ProjectOpenRequested
{
    public function __construct(
        public string $rootDir,
    ) {}
}
