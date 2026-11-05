<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;

/**
 * Whether a test run executed a line, as the change strip shows it while a
 * file's coverage is on screen — core's vocabulary, like {@see ChangeMarkKind}:
 * a plugin says which lines ran, core owns what that looks like. The strip's
 * own colours, added and deleted, because coverage takes the strip's place.
 */
enum CoverageMark
{
    case Covered;
    case Uncovered;

    public function role(): Role
    {
        return match ($this) {
            self::Covered => Role::VcsAdded,
            self::Uncovered => Role::VcsDeleted,
        };
    }

    /** Shape-distinct so the two read without colour: the line that never ran is the heavier bar. */
    public function glyph(): string
    {
        return match ($this) {
            self::Covered => Glyphs::COVERAGE_COVERED,
            self::Uncovered => Glyphs::COVERAGE_UNCOVERED,
        };
    }
}
