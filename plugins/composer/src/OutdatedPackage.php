<?php

declare(strict_types=1);

namespace Porta\Composer;

/** One direct dependency `composer outdated` reported: what is installed, and what is out. */
final readonly class OutdatedPackage
{
    /**
     * @param string      $status     `semver-safe-update` when the constraint already allows $latest, `update-possible` when it does not
     * @param string|null $abandoned  null while maintained, '' when abandoned for nothing, else the package to use instead
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $latest,
        public string $status,
        public ?string $abandoned = null,
    ) {}

    /** Whether `composer update` reaches $latest, or the constraint in composer.json has to change first. */
    public function withinConstraint(): bool
    {
        return 'semver-safe-update' === $this->status;
    }

    /** Whether there is a newer release at all: an abandoned package may be reported up to date. */
    public function behind(): bool
    {
        return 'up-to-date' !== $this->status && $this->version !== $this->latest;
    }

    /** `↑` an update the constraint allows, `⇑` one past it, `⚠` abandoned with neither. */
    public function glyph(): string
    {
        return match (true) {
            !$this->behind() => '⚠',
            $this->withinConstraint() => '↑',
            default => '⇑',
        };
    }
}
