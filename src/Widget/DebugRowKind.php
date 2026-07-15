<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The kind of a flattened {@see DebugRow} in the debugger panel: a section
 * heading, a stack frame, a watch expression, a variable scope, or a variable.
 * Drives per-row rendering and the Enter/click activation in
 * {@see DebugPanelWidget}.
 */
enum DebugRowKind
{
    case Section;
    case Frame;
    case Watch;
    case Scope;
    case Variable;
}
