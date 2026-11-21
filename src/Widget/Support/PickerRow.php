<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/** One row of the project picker: what it says, and what choosing it means. */
final readonly class PickerRow
{
    public function __construct(
        public PickerRowKind $kind,
        public string $label,
        public string $hint = '',
        public string $value = '',
    ) {}

    /** A section's title, drawn as a rule with $label as its legend; '' for a plain rule. */
    public static function heading(string $label): self
    {
        return new self(PickerRowKind::Heading, $label);
    }

    /** A line that only says something, dim, between the rows. */
    public static function note(string $text): self
    {
        return new self(PickerRowKind::Heading, '', $text);
    }

    public function isSelectable(): bool
    {
        return PickerRowKind::Heading !== $this->kind;
    }
}
