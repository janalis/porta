<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Event\GitRemoteCompleted;
use Porta\I18n\Translator;
use Porta\Ui\Notifier;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The remote git actions behind the palette/menu/git-view keys: fetch, pull
 * and push, each an async {@see GitRemote} call framed by status flashes. The
 * start flash is long-lived so it persists until the completion flash
 * replaces it; failures surface git's own first stderr line (auth, diverged
 * branches, missing upstream). Completion dispatches
 * {@see GitRemoteCompleted} so the branch/marks/history refresh listeners
 * react without coupling.
 */
final class GitRemoteCommands
{
    public function __construct(
        private readonly GitRemote $remote,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    public function fetch(): void
    {
        $this->operate('fetch', $this->translator->trans('Fetching…'), $this->translator->trans('Fetched'), fn (callable $onDone) => $this->remote->fetch($onDone));
    }

    public function pull(): void
    {
        $this->operate('pull', $this->translator->trans('Pulling…'), $this->translator->trans('Pulled'), fn (callable $onDone) => $this->remote->pull($onDone));
    }

    public function push(): void
    {
        $this->operate('push', $this->translator->trans('Pushing…'), $this->translator->trans('Pushed'), fn (callable $onDone) => $this->remote->push($onDone));
    }

    public function pushSetUpstream(): void
    {
        $this->operate('push', $this->translator->trans('Pushing…'), $this->translator->trans('Pushed'), fn (callable $onDone) => $this->remote->pushSetUpstream($onDone));
    }

    /** Route a git-view key ('fetch' | 'pull' | 'push') to its operation. */
    public function runOperation(string $operation): void
    {
        match ($operation) {
            'fetch' => $this->fetch(),
            'pull' => $this->pull(),
            'push' => $this->push(),
            default => null,
        };
    }

    /** @param \Closure(callable(bool, string): void): void $start */
    private function operate(string $operation, string $started, string $succeeded, \Closure $start): void
    {
        if (!$this->remote->isAvailable()) {
            $this->notifier->flash($this->translator->trans('Not a git repository (or git missing)'));

            return;
        }
        if ($this->remote->isBusy()) {
            $this->notifier->flash($this->translator->trans('A git remote operation is already running'));

            return;
        }
        // Sticky until the completion flash replaces it.
        $this->notifier->flash($started, 600.0);
        $start(function (bool $ok, string $output) use ($operation, $succeeded): void {
            $firstLine = strtok($output, "\n");
            $this->notifier->flash(
                $ok ? $succeeded : $this->translator->trans('%operation% failed: %error%', ['%operation%' => ucfirst($operation), '%error%' => \is_string($firstLine) ? $firstLine : '']),
                $ok ? 3.0 : 6.0,
            );
            $this->dispatcher->dispatch(new GitRemoteCompleted($operation, $ok));
        });
    }
}
