<?php

declare(strict_types=1);

namespace Porta\Onboarding;

/**
 * Answers one question for the Welcome screen: does this project ship mago?
 *
 * Only the project's own `vendor/bin` counts — a composer-installed mago is
 * evidence the *project* uses it, which is what switching the recommended
 * onboarding tools on. A machine-global mago (PATH) deliberately does not
 * qualify: it says nothing about this project's toolchain, and it would make
 * the choice differ per machine (including in the E2e suite, whose temp
 * projects must behave identically everywhere). A global-only mago user is
 * one Settings → Plugins toggle away, and the mago plugin's own runtime
 * binary lookup still falls back to PATH.
 */
final class MagoDetector
{
    public function __construct(
        private readonly string $rootDir,
    ) {}

    public function installed(): bool
    {
        $binary = $this->rootDir.'/vendor/bin/mago';

        return is_file($binary) && is_executable($binary);
    }
}
