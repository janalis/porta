<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\HelpViewerHandler;

/** Records every hook invocation; openHelp optionally mirrors the IDE's synchronous re-show. */
final class RecordingHelpViewerHandler implements HelpViewerHandler
{
    /** @var list<string> */
    public array $events = [];

    /** @var (\Closure(string): void)|null */
    public ?\Closure $onOpenHelp = null;

    public function openHelp(string $path): void
    {
        $this->events[] = 'help:'.\basename($path);
        if (null !== $this->onOpenHelp) {
            ($this->onOpenHelp)($path);
        }
    }

    public function openFile(string $path): void
    {
        $this->events[] = 'file:'.\basename($path);
    }

    public function openUrl(string $url): void
    {
        $this->events[] = 'url:'.$url;
    }

    public function missingTarget(string $target): void
    {
        $this->events[] = 'missing:'.$target;
    }

    public function openFind(): void
    {
        $this->events[] = 'find';
    }

    public function labelFor(string $path): string
    {
        return \basename($path, suffix: '.md');
    }

    public function backEmpty(): void
    {
        $this->events[] = 'back-empty';
    }

    public function cycleBuffer(int $offset): void
    {
        $this->events[] = 'cycle:'.$offset;
    }

    public function closeBuffer(): void
    {
        $this->events[] = 'close';
    }

    public function openGotoFile(): void
    {
        $this->events[] = 'goto';
    }

    public function viewChanged(): void
    {
        $this->events[] = 'view';
    }

    public function saveAll(): void
    {
        $this->events[] = 'save-all';
    }
}
