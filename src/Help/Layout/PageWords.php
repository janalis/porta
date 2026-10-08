<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\Node\AlertKind;
use Porta\Ui\Role;

/**
 * The words the page draws itself, in the reader's language — an alert's
 * title, a `<details>` with no summary of its own — and each alert's colour.
 * A word is asked for by name (`NOTE`, `DETAILS`) at every layout, so a
 * language switch reaches the next frame.
 */
final readonly class PageWords
{
    /** @param \Closure(string): string $translate a word's name to its text */
    public function __construct(
        private \Closure $translate,
    ) {}

    public static function english(): self
    {
        return new self(static fn (string $name): string => \ucfirst(\strtolower($name)));
    }

    public function title(AlertKind $kind): string
    {
        return ($this->translate)($kind->value);
    }

    /** What a `<details>` without a `<summary>` is called. */
    public function details(): string
    {
        return ($this->translate)('DETAILS');
    }

    public function role(AlertKind $kind): Role
    {
        return match ($kind) {
            AlertKind::Note, AlertKind::Tip => Role::TabAccent,
            AlertKind::Important => Role::Emphasis,
            AlertKind::Warning => Role::StatusWarning,
            AlertKind::Caution => Role::StatusError,
        };
    }
}
