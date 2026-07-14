<?php

declare(strict_types=1);

namespace Porta\Support;

use Porta\I18n\Translator;
use Porta\Ui\Notifier;

/**
 * Opens a documentation URL in the system browser (native-symbol docs from
 * go-to-declaration). Without an opener on PATH, flashes the URL itself —
 * most terminals auto-link it.
 */
final class DocsUrlOpener
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly string $rootDir,
    ) {}

    public function open(string $url): void
    {
        $command = BrowserOpener::command($url);
        if (null === $command) {
            $this->notifier->flash($this->translator->trans('Docs: %url%', ['%url%' => $url]), 5.0);

            return;
        }
        new AsyncProcess($command, $this->rootDir)->start(static function (): void {});
        $this->notifier->flash($this->translator->trans('Opened %url%', ['%url%' => $url]), 3.0);
    }
}
