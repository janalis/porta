<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Which way {@see MemberNavigation} walks the file's declarations.
 *
 * An enum rather than a `bool $forward`, for the reason {@see ProblemStep}
 * gives: the two verbs read the same at the call site and a flag would say
 * nothing at it.
 */
enum MemberStep
{
    case Next;
    case Previous;
}
