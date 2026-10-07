<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * One line of a tooltip card: its words in a role, an optional lead in a role
 * of its own (a problem's severity glyph), and an optional key set apart at
 * the end and dimmed, the way a menu shows a shortcut.
 *
 * A line that wraps keeps its lead on the first row only; the rows under it
 * are indented by the lead's width, so the words read as one paragraph.
 */
final readonly class TooltipLine
{
    public function __construct(
        public string $text,
        public string $key = '',
        public Role $role = Role::PopupRow,
        public string $lead = '',
        public ?Role $leadRole = null,
    ) {}
}
