<?php

declare(strict_types=1);

namespace Porta\Help;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\Editor\FindMode;
use Porta\Event\HelpPageRequested;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Layout\ViewRegion;
use Porta\Layout\ViewRegions;
use Porta\Support\Files;
use Porta\Support\ProjectPaths;
use Porta\Ui\CursorStyle;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Widget\FindBarWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewHost;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The documentation, in a window of its own like Settings: the page and,
 * above it while searching, a find bar of its own — the editor's sits in the
 * editor, behind the window. The page keeps its place, its links and its
 * back/forward history across closing and reopening, as the help tab did.
 *
 * The window holds the page alone: the region's sidebar and the line beside
 * it are hidden, never drawn.
 */
#[AsIdeService]
#[AsEager(priority: 450)]
final class HelpViewController implements ContributedView
{
    public const string ID = 'help';

    private readonly ViewRegion $region;

    private readonly FindBarWidget $find;

    private readonly HelpMouseRouter $mouse;

    private bool $open = false;

    public function __construct(
        private readonly HelpViewerWidget $page,
        ViewRegions $regions,
        private readonly ViewHost $host,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly ProjectPaths $paths,
        private readonly string $docsDir,
        ViewRegistry $views,
        HitTester $hit,
        Tui $tui,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
        ?ScreenDensity $density = null,
    ) {
        $this->find = new FindBarWidget($translator, $styles, $cursorStyle, $density);
        $this->find->setId('help-find');
        $this->find->onClose($this->focusPage(...));
        $this->region = $regions->mint(self::ID);
        $this->region->sidebar()->setStyle(new Style(hidden: true));
        $this->region->separator()->setStyle(new Style(hidden: true));
        $this->region->content()->add($this->find)->add($this->page);
        $this->mouse = new HelpMouseRouter($hit, $tui, $this->page, $this->find);
        $this->page->onExit($this->close(...));
        $views->register($this);
    }

    #[\Override]
    public function viewId(): string
    {
        return self::ID;
    }

    #[\Override]
    public function hostsWindow(): bool
    {
        return true;
    }

    #[\Override]
    public function title(): string
    {
        return $this->translator->trans('Documentation');
    }

    #[\Override]
    public function isOpen(): bool
    {
        return $this->open;
    }

    /** Open on the page the reader left, or on the docs' front page the first time. */
    #[\Override]
    public function open(): void
    {
        if ($this->open) {
            return;
        }
        if (null === $this->page->path()) {
            $this->showPage($this->docsDir.'/README.md');
        }
        if (null === $this->page->path()) {
            return; // the front page could not be read: the error says so
        }
        $this->open = true;
        $this->host->show($this->region, self::ID);
        $this->focusPage();
    }

    #[\Override]
    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        if ($this->find->isOpen()) {
            $this->find->close();
        }
        $this->host->restore();
    }

    #[\Override]
    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    /** Show the docs page at $path in the window, replacing what it shows. */
    public function showPage(string $path): void
    {
        $path = Files::canonical($path);
        if (!\is_file($path) || !\is_readable($path)) {
            $this->notifier->error($this->translator->trans('Cannot read %file%', ['%file%' => $this->paths->relative($path)]));

            return;
        }
        $markdown = Files::read($path);
        if ('' !== $markdown && !\mb_check_encoding($markdown, encoding: 'UTF-8')) {
            // Refused rather than rendered as mojibake, as every pane refuses
            // a file that is not text.
            $this->notifier->error($this->translator->trans('Not a UTF-8 text file'));

            return;
        }
        $this->page->refreshPage($path, $markdown);
        $this->host->invalidate();
    }

    /** A page asked for from outside the window — a plugin's docs, from its Settings page. */
    #[AsEventListener]
    public function onPageRequested(HelpPageRequested $event): void
    {
        $this->showPage($event->path);
        $this->open();
    }

    /** Ctrl+F on the page: the window's own find bar, searching the page. */
    public function openFind(): void
    {
        $this->find->open($this->page, FindMode::Find);
        $this->host->focus($this->find);
    }

    private function focusPage(): void
    {
        $this->host->focus($this->page);
        $this->host->invalidate();
    }

    #[\Override]
    public function pointer(): ?ViewPointer
    {
        return $this->mouse;
    }

    #[\Override]
    public function contextMenus(): ?ViewMenuSource
    {
        return null;
    }

    #[\Override]
    public function focusTarget(): AbstractWidget&FocusableInterface
    {
        return $this->find->isOpen() ? $this->find : $this->page;
    }

    /** F6 moves between the find bar and the page while searching; the page alone otherwise. */
    #[\Override]
    public function cycleFocus(int $direction): bool
    {
        $this->host->focus($this->find->isOpen() && $this->host->focused() !== $this->find ? $this->find : $this->page);

        return true;
    }
}
