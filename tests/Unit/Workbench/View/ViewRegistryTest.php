<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\View;

use PHPUnit\Framework\TestCase;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

final class ViewRegistryTest extends TestCase
{
    /**
     * A view core knows nothing about — which is the point: the registry takes
     * whatever registers, and the id travels on the view rather than being a
     * case core had to declare.
     */
    private function fakeView(string $id, bool $ownsMainArea = true, bool $hasRing = true): ContributedView
    {
        return new class($id, $ownsMainArea, $hasRing) implements ContributedView {
            private bool $open = false;

            private readonly AbstractWidget&FocusableInterface $widget;

            public function __construct(
                private readonly string $id,
                private readonly bool $ownsMainArea,
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

            public function viewId(): string
            {
                return $this->id;
            }

            public function ownsMainArea(): bool
            {
                return $this->ownsMainArea;
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

    public function testEveryQueryAnswersEmptyBeforeAnyViewRegisters(): void
    {
        // The input stack is built before the view controllers and asks these
        // questions in between. Nothing may throw and nothing may claim a view
        // is open.
        $views = new ViewRegistry();

        $this->assertFalse($views->isOpen('git'));
        $this->assertFalse($views->isAnyOpen());
        $this->assertNull($views->activeView());
        $this->assertNull($views->activeId());
        $this->assertNull($views->find('git'));
        $this->assertNull($views->focusTarget());
        $this->assertFalse($views->cycleFocus(1));
        $views->close('welcome');
        $views->toggle('git');
        $views->open('welcome');
        $views->closeAll();

        $this->addToAssertionCount(1);
    }

    public function testAViewIsRegisteredUnderTheIdItSupplies(): void
    {
        $views = new ViewRegistry();
        $contributed = $this->fakeView('acme');

        $views->register($contributed);

        $this->assertSame($contributed, $views->find('acme'), 'reachable by its own id, so a view keeps its own verbs');
        $this->assertNull($views->find('git'), 'and only by that one');
    }

    public function testToggleClosesEveryOtherViewFirst(): void
    {
        $views = new ViewRegistry();
        $views->register($this->fakeView('git'));
        $views->register($this->fakeView('settings'));

        $views->toggle('git');
        $this->assertSame('git', $views->activeId());
        $this->assertTrue($views->isAnyOpen());

        $views->toggle('settings');
        $this->assertFalse($views->isOpen('git'), 'the git view yielded');
        $this->assertSame('settings', $views->activeId());

        $views->toggle('settings');
        $this->assertNull($views->activeId(), 'a second toggle closes');
        $this->assertFalse($views->isAnyOpen());
    }

    public function testAFloatingViewIsActiveWithoutDetachingTheMainArea(): void
    {
        // The distinction ownsMainArea() exists for. Welcome is open — so it
        // owns focus and the menu's view-safe list — while the editor layout
        // stays mounted behind it, so every "is the editor detached?" guard
        // must still answer no.
        $views = new ViewRegistry();
        $views->register($this->fakeView('welcome', ownsMainArea: false, hasRing: false));

        $views->open('welcome');

        $this->assertTrue($views->isOpen('welcome'));
        $this->assertFalse($views->isAnyOpen(), 'Welcome floats: the main area is not detached');
        $this->assertSame('welcome', $views->activeId(), 'but it IS the active surface');

        $views->closeAll();
        $this->assertFalse($views->isOpen('welcome'));
    }

    public function testFocusFlowsToTheOpenViewAndRinglessViewsFallThrough(): void
    {
        $views = new ViewRegistry();
        $git = $this->fakeView('git');
        $welcome = $this->fakeView('welcome', ownsMainArea: false, hasRing: false);
        $views->register($git);
        $views->register($welcome);

        $views->toggle('git');
        $this->assertSame($git->focusTarget(), $views->focusTarget());
        $this->assertTrue($views->cycleFocus(1), 'the git view owns the ring');

        $views->toggle('welcome');
        $this->assertSame($welcome->focusTarget(), $views->focusTarget());
        $this->assertFalse($views->cycleFocus(1), 'the modal has no ring; the caller falls through to the editor');
    }
}
