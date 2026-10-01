<?php

declare(strict_types=1);

namespace Porta\Filesystem;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Workbench\Notifier;

/**
 * The gate in front of what runs the project on THIS machine — a run, a
 * terminal, a debugger. A remote project's cache holds only what was opened,
 * so a test suite run there would test placeholders: refused, and said why.
 */
#[AsIdeService]
final readonly class LocalOnly
{
    public function __construct(
        private ProjectLocation $location,
        private Notifier $notifier,
        private Translator $translator,
    ) {}

    /** Whether the project is not on this machine — said, when it is not. */
    public function refused(): bool
    {
        $remote = $this->location->remote;
        if (null === $remote) {
            return false;
        }
        $this->notifier->warn($this->translator->trans('This project is on %host%: running it needs it on this machine', ['%host%' => $remote->host]));

        return true;
    }
}
