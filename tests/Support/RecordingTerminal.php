<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/** An inner terminal that traces every call into $calls and keeps the kitty callback it was handed. */
final class RecordingTerminal implements TerminalInterface
{
    /**
     * @param list<string>   $calls
     * @param ?\Closure(): void $onStart what the inner terminal does as it boots, before the probe
     */
    public function __construct(
        private array &$calls,
        private readonly ?\Closure $onStart = null,
    ) {}

    /** @var (callable(): void)|null the kitty callback this inner was handed */
    public $kitty;

    public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
    {
        $this->calls[] = 'start';
        if (null !== $this->onStart) {
            ($this->onStart)();
        }
        $this->kitty = $onKittyProtocolActivated;
        $onInput('probe'); // prove the callbacks were passed through verbatim
    }

    public function stop(): void
    {
        $this->calls[] = 'stop';
    }

    public function write(string $data): void
    {
        $this->calls[] = 'write:'.$data;
    }

    public function getColumns(): int
    {
        return 123;
    }

    public function getRows(): int
    {
        return 45;
    }

    public function isKittyProtocolActive(): bool
    {
        return true;
    }

    public function moveBy(int $lines): void
    {
        $this->calls[] = 'moveBy';
    }

    public function hideCursor(): void
    {
        $this->calls[] = 'hideCursor';
    }

    public function showCursor(): void
    {
        $this->calls[] = 'showCursor';
    }

    public function clearLine(): void
    {
        $this->calls[] = 'clearLine';
    }

    public function clearFromCursor(): void
    {
        $this->calls[] = 'clearFromCursor';
    }

    public function clearScreen(): void
    {
        $this->calls[] = 'clearScreen';
    }

    public function setTitle(string $title): void
    {
        $this->calls[] = 'setTitle';
    }

    public function bell(): void
    {
        $this->calls[] = 'bell';
    }

    public function isVirtual(): bool
    {
        return false;
    }

    private ?EventDispatcherInterface $dispatcher = null;

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->dispatcher ??= new EventDispatcher();
    }
}
