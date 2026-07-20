<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

/**
 * The kind of a row in the settings navigation sidebar: a top-level section, a
 * per-plugin sub-item, a non-selectable heading ("Languages", "Version control"),
 * a language row, or the "Git" sub-item under Version control. Drives selection
 * skipping (headers are inert) and indentation in {@see SettingsNavWidget}.
 */
enum NavEntryKind
{
    case Section;
    case Plugin;
    case Header;
    case Language;
    case Vcs;
}
