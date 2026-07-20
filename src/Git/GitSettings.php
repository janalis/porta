<?php

declare(strict_types=1);

namespace Porta\Git;

/**
 * The mutable holder for the "Version control > Git" settings, shared by every
 * git-facing service (status bar, gutter, refresher, blame, commit, poll). It
 * is seeded from the resolved `.porta.json` settings at construction — so the
 * readers see the right initial state without a boot-apply pass — and the two
 * live toggles mutate it through {@see \Porta\Settings\LiveSettingsApplier}.
 * The git executable path is NOT here: it is consumed at construction by
 * {@see GitInfo}/{@see GitRemote} and is restart-required, so it travels the
 * env channel instead.
 */
final class GitSettings
{
    private bool $showBranch;
    private bool $gutterMarks;
    private bool $blameDefault;
    private bool $signCommits;
    private bool $noVerify;
    private bool $signoff;
    private int $refreshInterval;

    /** @param array<string, int|bool|string> $settings resolved settings (defaults merged) */
    public function __construct(array $settings)
    {
        $this->showBranch = (bool) ($settings['gitShowBranch'] ?? true);
        $this->gutterMarks = (bool) ($settings['gitGutterMarks'] ?? true);
        $this->blameDefault = (bool) ($settings['gitBlameDefault'] ?? false);
        $this->signCommits = (bool) ($settings['gitSignCommits'] ?? false);
        $this->noVerify = (bool) ($settings['gitNoVerify'] ?? false);
        $this->signoff = (bool) ($settings['gitSignoff'] ?? false);
        $this->refreshInterval = max(1, (int) ($settings['gitRefreshInterval'] ?? 2));
    }

    public function showBranch(): bool
    {
        return $this->showBranch;
    }

    public function setShowBranch(bool $on): void
    {
        $this->showBranch = $on;
    }

    public function gutterMarks(): bool
    {
        return $this->gutterMarks;
    }

    public function setGutterMarks(bool $on): void
    {
        $this->gutterMarks = $on;
    }

    public function blameDefault(): bool
    {
        return $this->blameDefault;
    }

    public function setBlameDefault(bool $on): void
    {
        $this->blameDefault = $on;
    }

    public function signCommits(): bool
    {
        return $this->signCommits;
    }

    public function setSignCommits(bool $on): void
    {
        $this->signCommits = $on;
    }

    public function noVerify(): bool
    {
        return $this->noVerify;
    }

    public function setNoVerify(bool $on): void
    {
        $this->noVerify = $on;
    }

    public function signoff(): bool
    {
        return $this->signoff;
    }

    public function setSignoff(bool $on): void
    {
        $this->signoff = $on;
    }

    public function refreshInterval(): int
    {
        return $this->refreshInterval;
    }

    public function setRefreshInterval(int $seconds): void
    {
        $this->refreshInterval = max(1, $seconds);
    }
}
