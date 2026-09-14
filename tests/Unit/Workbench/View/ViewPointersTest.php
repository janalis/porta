<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\View;

use PHPUnit\Framework\TestCase;
use Porta\Input\MouseEvent;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\ViewPointers;
use Porta\Workbench\View\WheelAxis;
use Porta\Workbench\View\WheelGesture;

/**
 * The lookup that replaced four `Input` classes holding GitMouseRouter and
 * SettingsMouseRouter as concrete dependencies.
 *
 * The null pointer is the load-bearing part: `mago.toml` pins the cyclomatic
 * ceiling at 145, floored by `MouseRouter::route()` whose guard ORDER is
 * behaviour. Returning null here would make every call site grow a branch, and
 * the gate would refuse the refactor for doing nothing wrong.
 */
final class ViewPointersTest extends TestCase
{
    private function press(): MouseEvent
    {
        $event = MouseEvent::parse("\x1b[<0;10;5M");
        $this->assertInstanceOf(MouseEvent::class, $event, 'the parser produced a mouse event');

        return $event;
    }

    private function recordingPointer(): ViewPointer
    {
        return new class implements ViewPointer {
            /** @var list<string> */
            public array $seen = [];

            public function continueDrag(MouseEvent $mouse): bool
            {
                $this->seen[] = 'continueDrag';

                return true;
            }

            public function cancelDrag(): void
            {
                $this->seen[] = 'cancelDrag';
            }

            public function press(MouseEvent $mouse): bool
            {
                $this->seen[] = 'press';

                return true;
            }

            public function hover(MouseEvent $mouse): void
            {
                $this->seen[] = 'hover';
            }

            public function wheel(WheelGesture $gesture): bool
            {
                $this->seen[] = 'wheel';

                return true;
            }
        };
    }

    public function testAViewsPointerAnswersUnderItsOwnId(): void
    {
        $pointers = new ViewPointers();
        $mine = $this->recordingPointer();
        $pointers->register('acme', $mine);

        $this->assertSame($mine, $pointers->of('acme'));
    }

    public function testAnUnknownOrAbsentIdYieldsAPointerThatConsumesNothing(): void
    {
        // Never null: the editor layout (no view open) is the common case, and
        // a null return would put a branch in every caller.
        $pointers = new ViewPointers();
        $pointers->register('acme', $this->recordingPointer());

        foreach ([null, 'nothing-registered-here'] as $id) {
            $none = $pointers->of($id);

            $this->assertFalse($none->continueDrag($this->press()), (string) $id);
            $this->assertFalse($none->press($this->press()), (string) $id);
            $this->assertFalse($none->wheel(new WheelGesture($this->press(), WheelAxis::Vertical, 3)), (string) $id);
            $none->cancelDrag();
            $none->hover($this->press());
        }

        $this->addToAssertionCount(1); // cancelDrag/hover are void: not throwing IS the assertion
    }

    public function testTheSameInstanceServesEveryUnregisteredId(): void
    {
        $pointers = new ViewPointers();

        $this->assertSame($pointers->of(null), $pointers->of('anything'), 'one shared no-op, not one per miss');
    }
}
