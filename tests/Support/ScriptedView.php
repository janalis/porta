<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewPointer;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/** A view whose open state and close behaviour the test scripts; it counts its closes. */
final class ScriptedView implements ContributedView
{
    public int $closes = 0;

    public function __construct(
        private readonly string $id,
        private bool $open = false,
        private readonly bool $throwOnClose = false,
        private readonly bool $hostsWindow = true,
    ) {}

    public function viewId(): string
    {
        return $this->id;
    }

    public function hostsWindow(): bool
    {
        return $this->hostsWindow;
    }

    public function title(): string
    {
        return '';
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
        ++$this->closes;
        if ($this->throwOnClose) {
            throw new \RuntimeException('close exploded');
        }
        $this->open = false;
    }

    public function toggle(): void
    {
        $this->open = !$this->open;
    }

    public function focusTarget(): AbstractWidget&FocusableInterface
    {
        return new CodeEditorWidget();
    }

    public function pointer(): ?ViewPointer
    {
        return null;
    }

    public function contextMenus(): ?ViewMenuSource
    {
        return null;
    }

    public function cycleFocus(int $direction): bool
    {
        return false;
    }
}
