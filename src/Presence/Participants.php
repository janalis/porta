<?php

declare(strict_types=1);

namespace Porta\Presence;

use Porta\Container\AsIdeService;
use Porta\Editor\EditorTextAccess;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\Event\ParticipantJoined;
use Porta\Event\ParticipantLeft;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Support\Scheduler;
use Porta\Ui\Glyphs;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusSegment;
use Porta\Workbench\StatusTone;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Tui\Tui;

/**
 * Everyone else at work on this project, whoever found them: another Porta
 * on the same files ({@see PresenceSync}), or a plugin's collaborators
 * (assist's guests and host). Each SOURCE replaces its own list whole; the
 * roster is all of them, and says so in the status bar — `⚇ 2`, the names
 * on hover, and a click lists them, each one a way to their file.
 *
 * A source that lost its link says so ({@see outOfTouch()}): its people stay,
 * as last seen, marked `⌁`, for {@see self::GRACE} seconds — a link back in
 * that time puts them back as they are; one that stays lost takes them out.
 */
#[AsIdeService]
#[AsTaggedItem(priority: 230)]
final class Participants implements StatusSegment
{
    /** How long a source's people stay after its link is lost, in seconds. */
    public const float GRACE = 60.0;

    /** @var array<string, array<string, Participant>> source => id => who */
    private array $sources = [];

    /** @var array<string, string> source out of touch => the timer that takes its people out */
    private array $lost = [];

    public function __construct(
        private readonly EventDispatcherInterface $events,
        private readonly Scheduler $scheduler,
        private readonly Translator $translator,
        private readonly Tui $tui,
        private readonly FileOpener $opener,
        private readonly EditorTextAccess $editor,
        private readonly ProjectPaths $paths,
        private readonly Identity $identity,
    ) {}

    /** What the roster knows $plugin's people by — so removing it takes them out. */
    public static function pluginSource(string $plugin): string
    {
        return 'plugin:'.$plugin;
    }

    /** Who this side is to everyone listed here. */
    public function yourName(): string
    {
        return $this->identity->name();
    }

    /**
     * $source's people, now: whoever was not in its last list joined, whoever
     * is not in this one left. In touch again, if it was not.
     *
     * @param list<Participant> $participants
     */
    public function set(string $source, array $participants): void
    {
        $this->found($source);
        $before = $this->sources[$source] ?? [];
        $now = [];
        foreach ($participants as $participant) {
            $now[$participant->id] = $participant;
        }
        // Replaced in place, so a source keeps its place in the roster.
        $this->sources[$source] = $now;
        if ([] === $now) {
            unset($this->sources[$source]);
        }
        foreach (\array_diff_key($before, $now) as $id => $gone) {
            $this->events->dispatch(new ParticipantLeft($source.'/'.$id, $gone->name, $gone->via));
        }
        foreach (\array_diff_key($now, $before) as $id => $came) {
            $this->events->dispatch(new ParticipantJoined($source.'/'.$id, $came->name, $came->via));
        }
        $this->tui->requestRender();
    }

    /** $source's link is lost: its people stay as last seen, for {@see self::GRACE} seconds at most. */
    public function outOfTouch(string $source): void
    {
        if (\array_key_exists($source, $this->lost) || !\array_key_exists($source, $this->sources)) {
            return;
        }
        $this->lost[$source] = $this->scheduler->delay(self::GRACE, function () use ($source): void {
            $this->set($source, []);
        });
        $this->tui->requestRender();
    }

    /** @return list<Participant> everyone, source by source */
    public function all(): array
    {
        $all = [];
        foreach ($this->sources as $participants) {
            \array_push($all, ...\array_values($participants));
        }

        return $all;
    }

    /** Whether $source's link is lost and its people are as last seen. */
    public function isOutOfTouch(string $source): bool
    {
        return \array_key_exists($source, $this->lost);
    }

    #[\Override]
    public function statusCells(): array
    {
        $all = $this->all();
        if ([] === $all) {
            return [];
        }
        $lost = [] !== $this->lost;
        $names = \implode(', ', \array_map(static fn (Participant $p): string => $p->name, $all));
        $tooltip = $this->translator->trans('Also at work here: %names%', ['%names%' => $names])."\n".$this->translator->trans('They see you as %name%', ['%name%' => $this->yourName()]);

        return [new StatusCell(
            Glyphs::PARTICIPANTS.' '.\count($all).($lost ? ' '.Glyphs::OUT_OF_TOUCH : ''),
            $lost ? StatusTone::Accent : StatusTone::Subtle,
            $this->menu(...),
            $lost ? $tooltip."\n".$this->translator->trans('The link to some of them is lost: shown as last seen, for a minute at most') : $tooltip,
        )];
    }

    /** @return list<MenuItem> one per participant: their file, at their caret */
    private function menu(): array
    {
        $items = [];
        foreach ($this->sources as $source => $participants) {
            foreach ($participants as $participant) {
                $path = $participant->path;
                $where = null === $path ? '' : ' · '.$this->paths->relative($path);
                $lost = \array_key_exists($source, $this->lost) ? ' '.Glyphs::OUT_OF_TOUCH : '';
                $items[] = new MenuItem(
                    $participant->name.' — '.$participant->via.$where.$lost,
                    '',
                    function () use ($path, $participant): void {
                        $this->goTo($path, $participant->line);
                    },
                    isEnabled: static fn (): bool => null !== $path,
                );
            }
        }

        return $items;
    }

    private function goTo(?string $path, int $line): void
    {
        if (null === $path) {
            return;
        }
        $this->opener->openFile($path, OpenFocus::Editor);
        if ($line >= 0) {
            $this->editor->jumpTo($line, 0);
        }
    }

    /** $source is in touch: whatever was going to take its people out is called off. */
    private function found(string $source): void
    {
        $timer = $this->lost[$source] ?? null;
        if (null !== $timer) {
            $this->scheduler->cancel($timer);
            unset($this->lost[$source]);
        }
    }
}
