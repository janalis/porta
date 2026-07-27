<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\FullScreenView;
use Porta\Ui\WorkspaceView;
use Porta\Ui\WorkspaceViews;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

final class WorkspaceViewsTest extends TestCase
{
    /** A minimal FullScreenView whose ring behavior is configurable. */
    private function fakeView(bool $hasRing = true): FullScreenView
    {
        return new class($hasRing) implements FullScreenView {
            private bool $open = false;

            private readonly AbstractWidget&FocusableInterface $widget;

            public function __construct(
                private readonly bool $hasRing,
            ) {
                $this->widget = new class extends AbstractWidget implements FocusableInterface {
                    use FocusableTrait;
                    use KeybindingsTrait;

                    public function render(RenderContext $context): array
                    {
                        return [];
                    }

                    public function handleInput(string $data): void {}
                };
            }

            public function isOpen(): bool
            {
                return $this->open;
            }

            public function open(): void
            {
                $this->open = true;
            }

            public function close(): void
            {
                $this->open = false;
            }

            public function toggle(): void
            {
                $this->open = !$this->open;
            }

            public function focusTarget(): AbstractWidget&FocusableInterface
            {
                return $this->widget;
            }

            public function cycleFocus(int $direction): bool
            {
                return $this->hasRing;
            }
        };
    }

    public function testAnswersFalseAndNoOpsUntilControllersAttach(): void
    {
        $views = new WorkspaceViews();

        $this->assertFalse($views->isAttached(), 'nothing attached at boot');
        $this->assertFalse($views->isOpen(WorkspaceView::Git));
        $this->assertFalse($views->isAnyOpen());
        $this->assertNull($views->activeView());
        $this->assertNull($views->focusTarget());
        $this->assertFalse($views->cycleFocus(1));
        $views->refreshSettings(); // a no-op, not an error
        $views->openGitFileHistory('/tmp/x'); // likewise before the controller attaches
        $views->close(WorkspaceView::Welcome);
        $views->toggle(WorkspaceView::Git);
        $views->open(WorkspaceView::Welcome);
        $views->closeAll();

        $this->addToAssertionCount(1);
    }

    public function testToggleClosesEveryOtherViewFirst(): void
    {
        $views = new WorkspaceViews();
        $views->attach(WorkspaceView::Git, $this->fakeView());
        $views->attach(WorkspaceView::Settings, $this->fakeView());

        $views->toggle(WorkspaceView::Git);
        $this->assertSame(WorkspaceView::Git, $views->activeView());
        $this->assertTrue($views->isAnyOpen());

        $views->toggle(WorkspaceView::Settings);
        $this->assertFalse($views->isOpen(WorkspaceView::Git), 'the git view yielded');
        $this->assertSame(WorkspaceView::Settings, $views->activeView());

        $views->toggle(WorkspaceView::Settings);
        $this->assertNull($views->activeView(), 'a second toggle closes');
        $this->assertFalse($views->isAnyOpen());
    }

    public function testWelcomeOpensWithoutDetachingTheMainArea(): void
    {
        $views = new WorkspaceViews();
        $views->attach(WorkspaceView::Welcome, $this->fakeView(hasRing: false));

        $views->open(WorkspaceView::Welcome);
        $this->assertTrue($views->isOpen(WorkspaceView::Welcome));
        $this->assertFalse($views->isAnyOpen(), 'Welcome floats: the main area is not detached');
        $this->assertSame(WorkspaceView::Welcome, $views->activeView(), 'but it IS the active surface');

        $views->closeAll();
        $this->assertFalse($views->isOpen(WorkspaceView::Welcome));
    }

    public function testFocusFlowsToTheOpenViewAndRinglessViewsFallThrough(): void
    {
        $views = new WorkspaceViews();
        $git = $this->fakeView();
        $welcome = $this->fakeView(hasRing: false);
        $views->attach(WorkspaceView::Git, $git);
        $views->attach(WorkspaceView::Welcome, $welcome);

        $views->toggle(WorkspaceView::Git);
        $this->assertSame($git->focusTarget(), $views->focusTarget());
        $this->assertTrue($views->cycleFocus(1), 'the git view owns the ring');

        $views->toggle(WorkspaceView::Welcome);
        $this->assertSame($welcome->focusTarget(), $views->focusTarget());
        $this->assertFalse($views->cycleFocus(1), 'no ring: the caller falls through to the editor ring');
    }

    public function testIsAttachedNeedsEveryMainAreaViewButNotWelcome(): void
    {
        $views = new WorkspaceViews();
        $views->attach(WorkspaceView::Git, $this->fakeView());
        $this->assertFalse($views->isAttached(), 'settings still missing');

        $views->attach(WorkspaceView::Settings, $this->fakeView());
        $this->assertTrue($views->isAttached(), 'Welcome floats — never part of the boot guard');
    }

    public function testOwnsMainAreaSplitsTheDetachingViewsFromTheFloatingOne(): void
    {
        $this->assertTrue(WorkspaceView::Git->ownsMainArea());
        $this->assertTrue(WorkspaceView::Settings->ownsMainArea());
        $this->assertFalse(WorkspaceView::Welcome->ownsMainArea());
    }
}
