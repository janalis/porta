<?php

declare(strict_types=1);

namespace Porta\Debug;

/** The production transport: a debug-adapter child process speaking DAP over stdio. */
final class ProcessDapClientFactory implements DapClientFactory
{
    public function __construct(
        private readonly string $rootDir,
    ) {}

    public function create(array $argv): DapClientInterface
    {
        return new DapClient($argv, $this->rootDir);
    }
}
