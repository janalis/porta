<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * An agent proposed new text for a file, and is waiting for the user's
 * verdict: `$path` is the file as it stands on disk, `$proposedPath` a copy
 * of what the agent would make it — same file name, in a temporary folder,
 * there until the verdict.
 *
 * A FACT, for whatever can SHOW two texts side by side: the bundled git
 * plugin answers with its comparison view. Nothing in core needs it, and with
 * no listener the proposal is still shown in the file and asked about.
 */
final readonly class ChangeProposed implements PluginObservable
{
    public function __construct(
        public string $path,
        public string $proposedPath,
    ) {}
}
