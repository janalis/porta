<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\DapCapabilities;

final class DapCapabilitiesTest extends TestCase
{
    public function testDefaultsAreAllFalse(): void
    {
        $capabilities = new DapCapabilities();

        $this->assertFalse($capabilities->configurationDone);
        $this->assertFalse($capabilities->conditionalBreakpoints);
        $this->assertFalse($capabilities->hitConditionalBreakpoints);
        $this->assertFalse($capabilities->logPoints);
        $this->assertFalse($capabilities->setVariable);
        $this->assertFalse($capabilities->restart);
    }

    public function testFromInitializeResponseReadsEachSupportsFlagIndependently(): void
    {
        $capabilities = DapCapabilities::fromInitializeResponse([
            'supportsConfigurationDoneRequest' => true,
            'supportsConditionalBreakpoints' => true,
            'supportsHitConditionalBreakpoints' => true,
            'supportsLogPoints' => true,
            'supportsSetVariable' => true,
            'supportsRestartRequest' => true,
        ]);

        $this->assertTrue($capabilities->configurationDone);
        $this->assertTrue($capabilities->conditionalBreakpoints);
        $this->assertTrue($capabilities->hitConditionalBreakpoints);
        $this->assertTrue($capabilities->logPoints);
        $this->assertTrue($capabilities->setVariable);
        $this->assertTrue($capabilities->restart);
    }

    public function testFromInitializeResponseDefaultsMissingFlagsToFalse(): void
    {
        $capabilities = DapCapabilities::fromInitializeResponse([]);

        $this->assertFalse($capabilities->configurationDone);
        $this->assertFalse($capabilities->conditionalBreakpoints);
        $this->assertFalse($capabilities->hitConditionalBreakpoints);
        $this->assertFalse($capabilities->logPoints);
        $this->assertFalse($capabilities->setVariable);
        $this->assertFalse($capabilities->restart);
    }

    public function testFromInitializeResponseRejectsANonTrueFlagValue(): void
    {
        // The adapter is required to send a boolean; a truthy-but-not-true
        // value (as some adapters have been seen to send) must not enable
        // the capability — the check is `true === ...`, not a cast.
        $capabilities = DapCapabilities::fromInitializeResponse(['supportsConditionalBreakpoints' => 1]);

        $this->assertFalse($capabilities->conditionalBreakpoints);
    }
}
