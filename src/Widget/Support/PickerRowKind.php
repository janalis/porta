<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/** What a row of the project picker is, and so what Enter and Tab do on it. */
enum PickerRowKind
{
    /** A section's title, or a line that only says something: never selected. */
    case Heading;

    /** A folder to open: its value is the resolved root. */
    case Project;

    /** A folder a typed path completes to: its value is spelled as the field spells it. */
    case Folder;

    /** A server's project: its value is the location as typed. */
    case Remote;

    /** Something to do rather than somewhere to go: its value names it. */
    case Action;
}
