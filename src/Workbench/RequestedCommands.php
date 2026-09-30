<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Config\CommandRegistry;
use Porta\Container\AsIdeService;
use Porta\Event\CommandRunRequested;
use Porta\I18n\Translator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The performer of {@see CommandRunRequested}: the command runs, or the user hears why it did not. */
#[AsIdeService]
final readonly class RequestedCommands
{
    public function __construct(
        private CommandRegistry $commands,
        private Notifier $notifier,
        private Translator $translator,
    ) {}

    #[AsEventListener]
    public function onCommandRunRequested(CommandRunRequested $event): void
    {
        if (!$this->commands->has($event->command)) {
            $this->notifier->warn($this->translator->trans('Nothing answers to %command% — is its plugin enabled?', ['%command%' => $event->command]));

            return;
        }
        $this->commands->run($event->command);
    }
}
