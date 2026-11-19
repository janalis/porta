<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Config\PortaHome;
use Porta\History\RecentProjectsStore;
use Porta\Support\ControlChars;
use Porta\Ui\NonBlockingTerminal;
use Porta\Workbench\RecentProjectChoices;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * What an icon click opens. A terminal launch has a working directory that
 * means something; a desktop launch has the home folder, which is no project.
 *
 * Runs before the IDE boots, because booting is what makes a folder a project:
 * it would land in the recent list and ask to be trusted.
 */
final class ProjectPicker
{
    private const string OPEN_FOLDER = "\0open-folder";

    private const int VISIBLE = 12;

    private ?string $picked = null;

    public function __construct(
        private readonly RecentProjectChoices $choices,
        private readonly TerminalInterface $terminal = new NonBlockingTerminal(new Terminal()),
    ) {}

    public static function forUser(): self
    {
        return new self(new RecentProjectChoices(new RecentProjectsStore()));
    }

    /** @return string|null the folder to open; null when the user left without choosing */
    public function pick(): ?string
    {
        $tui = new Tui(terminal: $this->terminal);
        $screen = new ContainerWidget();
        $tui->add($screen);

        $recent = $this->choices->except();
        match ($recent) {
            [] => $this->askForFolder($tui, $screen, recent: []),
            default => $this->showRecent($tui, $screen, $recent),
        };
        $tui->run();

        return $this->picked;
    }

    /** @param non-empty-list<array{label: string, hint: string, value: string}> $recent */
    private function showRecent(Tui $tui, ContainerWidget $screen, array $recent): void
    {
        $items = \array_map(static fn (array $item): array => ['value' => $item['value'], 'label' => $item['label'], 'description' => $item['hint']], $recent);
        $items[] = ['value' => self::OPEN_FOLDER, 'label' => 'Open folder…'];
        $list = new SelectListWidget($items, maxVisible: self::VISIBLE);
        $list->onSelect(function (SelectEvent $event) use ($tui, $screen, $recent): void {
            if (self::OPEN_FOLDER === $event->getValue()) {
                $this->askForFolder($tui, $screen, $recent);

                return;
            }
            $this->finish($tui, $event->getValue());
        });
        $list->onCancel(fn () => $this->finish($tui, folder: null));

        $screen->clear();
        $screen->add(new TextWidget('Open a project'));
        $screen->add($list);
        $screen->add(new TextWidget('↑↓ choose · Enter open · Esc quit'));
        $tui->setFocus($list);
    }

    /** @param list<array{label: string, hint: string, value: string}> $recent the list to go back to; empty when there is none */
    private function askForFolder(Tui $tui, ContainerWidget $screen, array $recent, string $problem = ''): void
    {
        $input = new InputWidget();
        $input->setValue('~/');
        $input->onSubmit(function (SubmitEvent $event) use ($tui, $screen, $recent): void {
            $folder = \realpath(PortaHome::expand(\trim($event->getValue())));
            if (false === $folder || !\is_dir($folder)) {
                $this->askForFolder($tui, $screen, $recent, 'Not a folder: '.ControlChars::line($event->getValue()));

                return;
            }
            $this->finish($tui, $folder);
        });
        $input->onCancel(function () use ($tui, $screen, $recent): void {
            match ($recent) {
                [] => $this->finish($tui, folder: null),
                default => $this->showRecent($tui, $screen, $recent),
            };
        });

        $screen->clear();
        $screen->add(new TextWidget('Open a project'));
        $screen->add($input);
        $screen->add(new TextWidget('' === $problem ? 'Enter open · Esc back' : $problem));
        $tui->setFocus($input);
    }

    private function finish(Tui $tui, ?string $folder): void
    {
        $this->picked = $folder;
        $tui->stop();
    }
}
