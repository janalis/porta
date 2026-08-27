<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

use Porta\Container\AsIdeService;
use Porta\Input\MouseEvent;
use Porta\Input\MouseRouter;

/**
 * Which {@see ViewPointer} answers for which view id.
 *
 * The mouse, hover and wheel routers used to hold GitMouseRouter and
 * SettingsMouseRouter as concrete constructor dependencies — four classes in
 * `Input` naming the git view — and dispatched to them with a guard apiece.
 * They ask here now, so a view core knows nothing about can answer for its own
 * region.
 *
 * An unregistered id yields a pointer that consumes nothing, so no caller grows
 * a null branch. That is not only tidiness: `mago.toml` pins the cyclomatic
 * ceiling at 145, floored by {@see MouseRouter::route()}, whose guard ORDER is
 * behaviour — adding branches there is how this refactor would have failed lint
 * while doing nothing wrong.
 */
#[AsIdeService]
final class ViewPointers
{
    /** @var array<string, ViewPointer> */
    private array $pointers = [];

    private readonly ViewPointer $none;

    public function __construct()
    {
        $this->none = new class implements ViewPointer {
            public function continueDrag(MouseEvent $mouse): bool
            {
                return false;
            }

            public function cancelDrag(): void {}

            public function press(MouseEvent $mouse): bool
            {
                return false;
            }

            public function hover(MouseEvent $mouse): void {}

            public function wheel(WheelGesture $gesture): bool
            {
                return false;
            }
        };
    }

    /** Each view's pointer registers itself when constructed. */
    public function register(string $viewId, ViewPointer $pointer): void
    {
        $this->pointers[$viewId] = $pointer;
    }

    /** Drop a retracted view's pointer; {@see of()} already answers with the null pointer. */
    public function forget(string $viewId): void
    {
        unset($this->pointers[$viewId]);
    }

    /** The pointer for $viewId — never null, so callers need no branch. */
    public function of(?string $viewId): ViewPointer
    {
        return null === $viewId ? $this->none : $this->pointers[$viewId] ?? $this->none;
    }
}
