<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * The capability set a DAP adapter advertised in its initialize response —
 * one value instead of six booleans that were always parsed together. All
 * false before initialize answers (the safe reading: offer nothing the
 * adapter has not claimed).
 */
final readonly class DapCapabilities
{
    public function __construct(
        public bool $configurationDone = false,
        public bool $conditionalBreakpoints = false,
        public bool $hitConditionalBreakpoints = false,
        public bool $logPoints = false,
        public bool $setVariable = false,
        public bool $restart = false,
    ) {}

    /** @param array<array-key, mixed> $body the initialize response body */
    public static function fromInitializeResponse(array $body): self
    {
        return new self(
            configurationDone: true === ($body['supportsConfigurationDoneRequest'] ?? false),
            conditionalBreakpoints: true === ($body['supportsConditionalBreakpoints'] ?? false),
            hitConditionalBreakpoints: true === ($body['supportsHitConditionalBreakpoints'] ?? false),
            logPoints: true === ($body['supportsLogPoints'] ?? false),
            setVariable: true === ($body['supportsSetVariable'] ?? false),
            restart: true === ($body['supportsRestartRequest'] ?? false),
        );
    }
}
