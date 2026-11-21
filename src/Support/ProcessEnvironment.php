<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * The private handoffs belong to one IDE launch, never its tools or shells: the
 * restart directory, and the desktop host's session. A shell in the integrated
 * terminal that kept the latter would start a Porta believing a window owned it.
 */
final class ProcessEnvironment
{
    /**
     * @param array<string, string> $environment
     * @return array<string, string>
     */
    public static function withoutRestart(array $environment): array
    {
        unset($environment['PORTA_DESKTOP'], $environment['PORTA_DESKTOP_TOKEN'], $environment['PORTA_DESKTOP_MENU'], $environment['PORTA_DESKTOP_APPEARANCE']);

        return self::forNestedPorta($environment);
    }

    /**
     * For the Porta that takes over this very terminal: it answers to the same
     * window, so only the restart handoff stays behind.
     *
     * @param array<string, string> $environment
     * @return array<string, string>
     */
    public static function forNestedPorta(array $environment): array
    {
        unset($environment['PORTA_RESTART_DIRECTORY'], $environment['PORTA_RESTART_CHECK_AFTER']);

        return $environment;
    }

    public static function restartCheckAfter(?string $value): int
    {
        return null !== $value && \ctype_digit($value) ? (int) $value : 0;
    }
}
