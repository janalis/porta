<?php

declare(strict_types=1);

namespace Porta\Tests\Behat;

use Janalis\Tuink\Driver\InProcessApplicationInterface;
use Porta\Ide;
use Porta\Tests\Support\Services;
use Symfony\Component\Tui\Tui;

/**
 * The Ide as tuink's in-process driver hosts it: boot() and shutdown() are the
 * halves of Ide::run() around the loop the driver turns itself.
 */
final readonly class PortaApplication implements InProcessApplicationInterface
{
    public function __construct(
        public Ide $ide,
    ) {}

    public function getTui(): Tui
    {
        return Services::resolve($this->ide, Tui::class);
    }

    public function boot(): void
    {
        $this->ide->boot();
    }

    public function shutdown(): void
    {
        $this->ide->shutdown();
    }
}
