<?php

declare(strict_types=1);

namespace Porta\Container;

/**
 * Whether a freshly compiled container is served directly (env placeholders
 * resolved at build time) or prepared for the PhpDumper (placeholders kept as
 * runtime lookups so the dumped file stays project-independent).
 */
enum CompileTarget
{
    case Direct;
    case Dump;
}
