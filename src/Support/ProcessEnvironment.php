<?php

declare(strict_types=1);

namespace Porta\Support;

/** The private restart handoff belongs to one IDE launch, never its tools or shells. */
final class ProcessEnvironment
{
    /**
     * @param array<string, string> $environment
     * @return array<string, string>
     */
    public static function withoutRestart(array $environment): array
    {
        unset($environment['PORTA_RESTART_DIRECTORY'], $environment['PORTA_RESTART_CHECK_AFTER']);

        return $environment;
    }

    public static function restartCheckAfter(?string $value): int
    {
        return null !== $value && \ctype_digit($value) ? (int) $value : 0;
    }
}
