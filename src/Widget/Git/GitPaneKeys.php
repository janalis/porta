<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Widget\PaneNavigationCallbacks;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * The keys the two git list panes share: the remote-operation trio
 * (fetch/pull/push) plus the pane-navigation pair. Composes
 * {@see PaneNavigationCallbacks} rather than sitting beside it, so mago's
 * isolated trait analysis sees $onExit/$onFocusNext/fire() as members here
 * and each widget states one dependency instead of two.
 */
trait GitPaneKeys
{
    use PaneNavigationCallbacks;

    /** @var (callable(string): void)|null a remote-operation key: 'fetch' | 'pull' | 'push' */
    private $onRemote = null;

    public function onRemote(callable $callback): self
    {
        $this->onRemote = $callback;

        return $this;
    }

    /** Handle a key shared by the git list panes; true when it consumed the key. */
    private function handleGitPaneKey(string $data, Keybindings $kb): bool
    {
        foreach (['fetch', 'pull', 'push'] as $remoteOp) {
            if (!$kb->matches($data, $remoteOp)) {
                continue;
            }

            if (null !== $this->onRemote) {
                ($this->onRemote)($remoteOp);
            }

            return true;
        }
        if ($kb->matches($data, 'exit_view')) {
            $this->fire($this->onExit);

            return true;
        }
        if ($kb->matches($data, 'focus_next_pane')) {
            $this->fire($this->onFocusNext);

            return true;
        }

        return false;
    }
}
