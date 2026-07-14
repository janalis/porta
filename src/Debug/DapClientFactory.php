<?php

declare(strict_types=1);

namespace Porta\Debug;

/** Builds the DAP transport for an adapter's argv; tests inject fakes. */
interface DapClientFactory
{
    /** @param list<string> $argv */
    public function create(array $argv): DapClientInterface;
}
