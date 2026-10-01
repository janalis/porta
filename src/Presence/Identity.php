<?php

declare(strict_types=1);

namespace Porta\Presence;

use Porta\Container\AsIdeService;

/**
 * Who this Porta is to the others on the project: the name they see beside
 * this side's caret and in their roster — the `displayName` setting, else the
 * login name — and whether it says so at all (`sharePresence`). Set by the
 * settings, at boot and live.
 */
#[AsIdeService]
final class Identity
{
    private string $name = '';

    private bool $sharing = true;

    /** The name the others see: never empty, never longer than a beacon carries. */
    public function name(): string
    {
        $user = \getenv('USER');
        $fallback = Beacon::clean(\is_string($user) ? $user : '');

        return match (true) {
            '' !== $this->name => $this->name,
            '' !== $fallback => $fallback,
            default => 'anonymous',
        };
    }

    public function rename(string $name): void
    {
        $this->name = Beacon::clean($name);
    }

    /** Whether the others are told this side is here. Seeing them does not depend on it. */
    public function sharing(): bool
    {
        return $this->sharing;
    }

    public function share(bool $sharing): void
    {
        $this->sharing = $sharing;
    }
}
