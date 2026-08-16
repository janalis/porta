<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Ui\Glyphs;
use Porta\Ui\Role;

/**
 * A line's version-control status in the editor gutter — core's own
 * vocabulary, per {@see ChangeMarkSink}: a producer (a plugin's
 * `setChangeMarks()` call) says which of the three a line is, and core owns
 * what that looks like. String-backed because {@see \Porta\Plugin\Api\PluginApi::setChangeMarks()}
 * is the boundary a plugin calls with a bare string.
 */
enum ChangeMarkKind: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';

    public function role(): Role
    {
        return match ($this) {
            self::Added => Role::VcsAdded,
            self::Modified => Role::VcsModified,
            self::Deleted => Role::VcsDeleted,
        };
    }

    /** Shape-distinct so the states read without color. */
    public function glyph(): string
    {
        return match ($this) {
            self::Added => Glyphs::VCS_ADDED, // green solid bar
            self::Modified => Glyphs::VCS_MODIFIED, // blue dashed bar
            self::Deleted => Glyphs::VCS_DELETED, // red underline
        };
    }
}
