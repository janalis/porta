<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Container\AsIdeService;
use Porta\Overlay\Region\CloseButton;

/**
 * The platform's window-control conventions, read once: every floating
 * window puts its × where the desktop it runs on puts a window's close
 * button. A surface built without it (a test, a bare widget) keeps the × on
 * the right.
 */
#[AsIdeService]
final readonly class WindowControls
{
    public CloseButton $closeButton;

    public function __construct(string $os = \PHP_OS_FAMILY)
    {
        $this->closeButton = CloseButton::forOs($os);
    }
}
