<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Editor\EditorDecorationSink;
use Porta\Event\AgentActivity;
use Porta\Event\AgentLeft;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\Support\ProjectPaths;
use Porta\Support\Scheduler;
use Porta\Ui\Glyphs;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * Which agents are at work on which files, as the IDE shows it: what
 * {@see AgentActivity} reported, until it goes quiet or {@see AgentLeft}.
 *
 * Each agent keeps a colour SLOT, in order of arrival, for as long as it is
 * shown — its caret, its label and its tab mark are the same colour
 * everywhere. Each (agent, file) keeps its own fade timer, re-armed by every
 * report, so an agent reading one file and editing another shows on both
 * until each goes quiet.
 *
 * The editor's carets are pushed to the decoration store one by one, as each
 * report moves them — the store shifts them with the user's own edits in
 * between, so a push never drags back a caret this class has not heard
 * about since. The tab bar and the tree pull {@see in()} when they render.
 */
#[AsIdeService]
final class AgentPresence
{
    /** How many agent colours the themes define. */
    public const int SLOTS = 8;

    /** @var array<string, int> agent => colour slot */
    private array $slots = [];

    /** @var array<string, array<string, array{name: string, kind: AgentActivityKind, line: int, column: int, anchor: array{line: int, col: int}|null, timer: string}>> path => agent => what it is doing */
    private array $files = [];

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly EditorDecorationSink $decorations,
        private readonly Tui $tui,
    ) {}

    #[AsEventListener]
    public function onAgentActivity(AgentActivity $event): void
    {
        $path = self::canonical($event->path);
        $this->slots[$event->agent] ??= $this->freeSlot();
        $previous = $this->files[$path][$event->agent]['timer'] ?? null;
        if (null !== $previous) {
            $this->scheduler->cancel($previous);
        }
        // The timer finds its entry by its own id, not by the path it was
        // armed for: a rename moves the entry, and the fade must follow it.
        $timer = '';
        $timer = $this->scheduler->delay($event->fadeAfter, function () use (&$timer): void {
            $this->fade($timer);
        });
        $this->files[$path][$event->agent] = [
            'name' => $event->name,
            'kind' => $event->kind,
            'line' => $event->line,
            'column' => \max(0, $event->column),
            'anchor' => $event->anchorLine < 0 ? null : ['line' => $event->anchorLine, 'col' => \max(0, $event->anchorColumn)],
            'timer' => $timer,
        ];
        $this->paint($path, $event->agent);
        $this->changed();
    }

    #[AsEventListener]
    public function onAgentLeft(AgentLeft $event): void
    {
        foreach (\array_keys($this->files) as $path) {
            $this->cancelAndForget($path, $event->agent);
        }
        unset($this->slots[$event->agent]);
        $this->changed();
    }

    #[AsEventListener]
    public function onFileRenamed(FileRenamed $event): void
    {
        foreach ($this->files as $path => $agents) {
            $moved = ProjectPaths::moved($path, $event->from, $event->to);
            if (null === $moved) {
                continue;
            }
            unset($this->files[$path]);
            $this->files[$moved] = $agents;
            foreach (\array_keys($agents) as $agent) {
                $this->decorations->setRemoteCaret($path, $agent, null);
                $this->paint($moved, $agent);
            }
        }
        $this->changed();
    }

    #[AsEventListener]
    public function onFileDeleted(FileDeleted $event): void
    {
        foreach (\array_keys($this->files[$event->path] ?? []) as $agent) {
            $this->cancelAndForget($event->path, $agent);
        }
        $this->changed();
    }

    /**
     * Who is at work on $path, first arrived first.
     *
     * @return list<array{name: string, kind: AgentActivityKind, slot: int}>
     */
    public function in(string $path): array
    {
        $agents = [];
        foreach ($this->files[$path] ?? [] as $agent => $entry) {
            $agents[] = ['name' => $entry['name'], 'kind' => $entry['kind'], 'slot' => $this->slots[$agent] ?? 0];
        }

        return $agents;
    }

    /** @return list<string> every file an agent is at work on */
    public function files(): array
    {
        return \array_keys($this->files);
    }

    /** A fade timer fired: whatever entry it was armed for goes, wherever it is now. */
    private function fade(string $timer): void
    {
        foreach ($this->files as $path => $agents) {
            foreach ($agents as $agent => $entry) {
                if ($timer !== $entry['timer']) {
                    continue;
                }
                $this->forget($path, $agent);
            }
        }
        $this->changed();
    }

    /** The lowest slot no agent holds, or the first again once all are taken. */
    private function freeSlot(): int
    {
        $taken = \array_flip($this->slots);
        for ($slot = 0; $slot < self::SLOTS; ++$slot) {
            if (!\array_key_exists($slot, $taken)) {
                return $slot;
            }
        }

        return \count($this->slots) % self::SLOTS;
    }

    /** A leave or a delete: the fade timer has not fired yet, so it is cancelled first. */
    private function cancelAndForget(string $path, string $agent): void
    {
        $timer = $this->files[$path][$agent]['timer'] ?? null;
        if (null !== $timer) {
            $this->scheduler->cancel($timer);
        }
        $this->forget($path, $agent);
    }

    /** Drop $agent's caret in $path — after a fade, whose timer has already fired. */
    private function forget(string $path, string $agent): void
    {
        if (null === ($this->files[$path][$agent] ?? null)) {
            return;
        }
        unset($this->files[$path][$agent]);
        $this->decorations->setRemoteCaret($path, $agent, null);
        if ([] === $this->files[$path]) {
            unset($this->files[$path]);
        }
    }

    /** $agent's caret in $path, as the editor draws it — none on a negative line. */
    private function paint(string $path, string $agent): void
    {
        $entry = $this->files[$path][$agent];
        $this->decorations->setRemoteCaret(
            $path,
            $agent,
            $entry['line'] < 0
                ? null
                : [
                    'line' => $entry['line'],
                    'col' => $entry['column'],
                    'label' => Glyphs::AGENT.' '.$entry['name'],
                    'slot' => $this->slots[$agent],
                    'anchor' => $entry['anchor'],
                ],
        );
    }

    private function changed(): void
    {
        $this->tui->requestRender();
    }

    /** Buffers are keyed by realpath; a file already gone keeps the path it was given. */
    private static function canonical(string $path): string
    {
        $real = \realpath($path);

        return false === $real ? $path : $real;
    }
}
