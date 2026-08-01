<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Event\GitChangeDiscardRequested;
use Porta\Event\GitChangeSelected;
use Porta\Event\GitChangeStageToggleRequested;
use Porta\Event\GitCommitFileSelected;
use Porta\Event\GitCommitSelected;
use Porta\Event\GitDiffContextCycleRequested;
use Porta\Event\GitDiffCopyRequested;
use Porta\Event\GitDiffLayoutToggled;
use Porta\Event\GitDiffStagingRequested;
use Porta\Event\GitDiffWhitespaceToggleRequested;
use Porta\Event\GitHistoryEndReached;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Registers listeners for the git widgets' events from closures shaped like
 * the callbacks they replaced.
 *
 * The widgets dispatch now, but a test still wants to say "when the selection
 * moves, record the entry" in one line. Without this the same six-line
 * addListener block would appear 28 times across three files, and the
 * assertions — which are the point — would be harder to find than the wiring.
 *
 * The mapping from old slot name to event lives here, once, which is also the
 * place to look when a test written against the closures needs porting.
 */
final class GitWidgetEvents
{
    public static function wire(
        EventDispatcher $events,
        ?callable $onSelect = null,
        ?callable $onToggleStage = null,
        ?callable $onDiscard = null,
        ?callable $onSelectCommit = null,
        ?callable $onSelectFile = null,
        ?callable $onLoadMore = null,
        ?callable $onStageSelection = null,
        ?callable $onCycleContext = null,
        ?callable $onLayoutToggle = null,
        ?callable $onToggleWhitespace = null,
        ?callable $onCopy = null,
    ): void {
        if (null !== $onSelect) {
            $events->addListener(GitChangeSelected::class, static fn (GitChangeSelected $event) => $onSelect($event->entry));
        }
        if (null !== $onToggleStage) {
            $events->addListener(GitChangeStageToggleRequested::class, static fn (GitChangeStageToggleRequested $event) => $onToggleStage($event->entry));
        }
        if (null !== $onDiscard) {
            $events->addListener(GitChangeDiscardRequested::class, static fn (GitChangeDiscardRequested $event) => $onDiscard($event->entry));
        }
        if (null !== $onSelectCommit) {
            $events->addListener(GitCommitSelected::class, static fn (GitCommitSelected $event) => $onSelectCommit($event->commit));
        }
        if (null !== $onSelectFile) {
            $events->addListener(GitCommitFileSelected::class, static fn (GitCommitFileSelected $event) => $onSelectFile($event->commit, $event->path));
        }
        if (null !== $onLoadMore) {
            $events->addListener(GitHistoryEndReached::class, static fn (GitHistoryEndReached $event) => $onLoadMore());
        }
        if (null !== $onStageSelection) {
            $events->addListener(GitDiffStagingRequested::class, static fn (GitDiffStagingRequested $event) => $onStageSelection($event->selection));
        }
        if (null !== $onCycleContext) {
            $events->addListener(GitDiffContextCycleRequested::class, static fn (GitDiffContextCycleRequested $event) => $onCycleContext());
        }
        if (null !== $onLayoutToggle) {
            $events->addListener(GitDiffLayoutToggled::class, static fn (GitDiffLayoutToggled $event) => $onLayoutToggle());
        }
        if (null !== $onToggleWhitespace) {
            $events->addListener(GitDiffWhitespaceToggleRequested::class, static fn (GitDiffWhitespaceToggleRequested $event) => $onToggleWhitespace());
        }
        if (null !== $onCopy) {
            $events->addListener(GitDiffCopyRequested::class, static fn (GitDiffCopyRequested $event) => $onCopy($event->text));
        }
    }
}
