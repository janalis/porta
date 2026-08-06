<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Event\BufferSaved;
use Porta\Event\GitChangeDiscardRequested;
use Porta\Event\GitChangeSelected;
use Porta\Event\GitChangeStageToggleRequested;
use Porta\Event\GitCommitFileSelected;
use Porta\Event\GitCommitSelected;
use Porta\Event\GitCommitted;
use Porta\Event\GitDiffContextCycleRequested;
use Porta\Event\GitDiffCopyRequested;
use Porta\Event\GitDiffLayoutToggled;
use Porta\Event\GitDiffStagingRequested;
use Porta\Event\GitDiffWhitespaceToggleRequested;
use Porta\Event\GitFileHistoryRequested;
use Porta\Event\GitHistoryEndReached;
use Porta\Event\GitRemoteCompleted;
use Porta\Event\GitRemoteRequested;
use Porta\Event\GitViewModeRequested;
use Porta\Event\WorkingTreeChanged;
use Porta\Git\GitRefresher;
use Porta\Git\GitViewController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The nineteen git reactions, pinned as an explicit event → method map.
 *
 * These are wired by an ATTRIBUTE the container reads, which is a feature that
 * evaporates the moment the class stops being a service — and the failure is
 * silent. Nothing throws; a reaction simply never happens, and only a test that
 * happened to walk that exact path would notice. `EventListenerWiringTest`
 * asserts every event in `src/Event` has SOMEWHERE to land, but when git leaves
 * `src/` these events leave with it, so that guarantee goes too.
 *
 * This is the checklist the move is measured against: hand-registering
 * eighteen of nineteen is a working IDE with one dead interaction.
 *
 * The assertion is two-directional on purpose — a listener that disappears
 * fails, and so does one that appears without being written down here.
 */
final class GitListenerContractTest extends TestCase
{
    /** @var array<class-string, array<class-string, string>> */
    private const array EXPECTED = [
        GitViewController::class => [
            GitChangeDiscardRequested::class => 'onChangeDiscarded',
            GitChangeSelected::class => 'onChangeSelected',
            GitChangeStageToggleRequested::class => 'onChangeStageToggled',
            GitCommitFileSelected::class => 'onCommitFileSelected',
            GitCommitSelected::class => 'onCommitSelected',
            GitDiffContextCycleRequested::class => 'onDiffContextCycled',
            GitDiffCopyRequested::class => 'onDiffTextCopied',
            GitDiffLayoutToggled::class => 'onDiffLayoutToggled',
            GitDiffStagingRequested::class => 'onDiffLinesStaged',
            GitDiffWhitespaceToggleRequested::class => 'onDiffWhitespaceToggled',
            GitFileHistoryRequested::class => 'onFileHistoryRequested',
            GitHistoryEndReached::class => 'onHistoryEndReached',
            GitRemoteCompleted::class => 'onRemoteCompleted',
            GitRemoteRequested::class => 'onRemoteRequested',
            GitViewModeRequested::class => 'onViewModeRequested',
        ],
        GitRefresher::class => [
            BufferSaved::class => 'onBufferSaved',
            GitCommitted::class => 'onGitCommitted',
            GitRemoteCompleted::class => 'onGitRemoteCompleted',
            WorkingTreeChanged::class => 'onWorkingTreeChanged',
        ],
    ];

    public function testTheGitListenerSetIsExactlyThisAndNothingElse(): void
    {
        foreach (self::EXPECTED as $class => $expected) {
            $this->assertSame($expected, self::listenersOf($class), $class.': the wired set drifted from the pinned one');
        }
    }

    public function testTheSetIsNineteenReactionsAcrossTwoClasses(): void
    {
        // A count as well as a map: it is the number to check a hand-registered
        // replacement against at a glance, and it is what makes "one of these
        // was dropped" a visible arithmetic failure rather than a diff to read.
        $total = array_sum(array_map(static fn (array $map): int => \count($map), self::EXPECTED));

        $this->assertSame(19, $total);
        $this->assertCount(2, self::EXPECTED);
    }

    /**
     * Every `#[AsEventListener]` method on $class, as the event class it takes
     * mapped to the method that takes it, sorted so the comparison is stable.
     *
     * Read from the attribute rather than from a compiled dispatcher: this has
     * to keep working after the class stops being a service, which is the whole
     * situation it exists for.
     *
     * @param class-string $class
     *
     * @return array<class-string, string>
     */
    private static function listenersOf(string $class): array
    {
        $found = [];
        foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ([] === $method->getAttributes(AsEventListener::class)) {
                continue;
            }
            $parameters = $method->getParameters();
            $type = [] === $parameters ? null : $parameters[0]->getType();
            $found[$type instanceof \ReflectionNamedType ? $type->getName() : '(untyped)'] = $method->getName();
        }
        ksort($found);

        return $found;
    }
}
