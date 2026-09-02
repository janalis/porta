<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Which way {@see DiagnosticNavigation} walks the file's problems.
 *
 * An enum rather than a `bool $forward`, because the two verbs read the same
 * at the call site and the flag would say nothing at it — the shape every
 * other two-way choice in this namespace already has ({@see OpenFocus},
 * {@see SelectionMode}).
 */
enum ProblemStep
{
    case Next;
    case Previous;
}
