<?php

declare(strict_types=1);

namespace Porta\Debug;

/** One language's resolved debug setup: the adapter process and launch overrides. */
final readonly class DebugConfig
{
    /**
     * @param list<string>         $adapterArgv the DAP adapter command
     * @param array<string, mixed> $launch      user overrides merged into the launch request
     */
    public function __construct(
        public array $adapterArgv,
        public array $launch = [],
    ) {}
}
