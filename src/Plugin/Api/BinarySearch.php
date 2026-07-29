<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * How a tool plugin looks for its binary once the `binary` setting is empty.
 * A pure enum rather than a boolean flag: the two cases are real vocabulary
 * (a composer-installed tool has a `vendor/bin` shim, a Rust or Go one never
 * does), and mago rejects boolean flag parameters.
 */
enum BinarySearch
{
    /** The composer default: `<project>/vendor/bin/<tool>`, then PATH. */
    case VendorBinThenPath;

    /** PATH only — for tools composer never installs (phpantom's Rust binary). */
    case PathOnly;
}
