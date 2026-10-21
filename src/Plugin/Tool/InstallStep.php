<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

/**
 * Where an install has got to, so the UI can say something between the
 * opening flash and the closing toast.
 *
 * A ~9MB download behind two curl calls, a hash, a tar and a smoke test used
 * to be a single "downloading the latest release…" flash that expired after
 * four seconds, leaving nothing on screen for the rest of the operation.
 *
 * The cases are what happens, not what to print: the wording and its catalog
 * entries belong to whoever shows it, which is the half that owns a Translator.
 */
enum InstallStep
{
    /** Asking GitHub which release is the latest one. */
    case Resolving;

    /** Fetching the platform asset — the only step that carries a percentage. */
    case Downloading;

    /** Fetching the checksum file and hashing the asset against it. */
    case Verifying;

    case Extracting;

    /** Running the staged binary, before it replaces a working one. */
    case Checking;
}
