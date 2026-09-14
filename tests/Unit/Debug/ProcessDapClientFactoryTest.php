<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\DapClient;
use Porta\Debug\ProcessDapClientFactory;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;

final class ProcessDapClientFactoryTest extends TestCase
{
    public function testTheClientIsWiredToTheDapDiagnosticLog(): void
    {
        $project = new TempProject();
        $factory = new ProcessDapClientFactory($project->dir);

        $client = $factory->create([\PHP_BINARY, '-v']);

        $this->assertInstanceOf(DapClient::class, $client);
        $logger = Probe::get(Probe::object($client, 'transport'), 'logger');
        $this->assertIsCallable($logger, 'the factory hands the transport a logger — a dead adapter must leave forensics');
        $logger('adapter said something');
        $this->assertStringContainsString('adapter said something', (string) \file_get_contents($project->dir.'/.porta/dap.log'));
    }
}
