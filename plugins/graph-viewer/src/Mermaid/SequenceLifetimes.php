<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Lifecycle directives are anchored to their associated message, never their declaration row. */
final class SequenceLifetimes
{
    /** @var array<'create'|'destroy', array{actor: string, statement: MermaidStatement}> */
    private array $pending = [];

    public function __construct(
        private readonly SequenceDiagram $diagram,
    ) {}

    /** @param 'create'|'destroy' $action */
    public function declare(string $action, string $actor, MermaidStatement $statement): void
    {
        if (\array_key_exists($action, $this->pending)) {
            throw $statement->error('Lifecycle directive requires an associated message before another directive');
        }
        if ('create' === $action && \array_key_exists($actor, $this->diagram->participants)) {
            throw $statement->error('Cannot create an existing sequence participant');
        }
        if ('destroy' === $action && null !== ($this->diagram->lifetimes[$actor]['destroy'] ?? null)) {
            throw $statement->error('Cannot destroy a sequence participant twice');
        }
        $this->pending[$action] = ['actor' => $actor, 'statement' => $statement];
        $this->diagram->event($actor, $actor, '', '', '', $statement, kind: $action);
    }

    public function message(string $from, string $to, int $index): void
    {
        foreach ($this->pending as $action => $pending) {
            $actor = $pending['actor'];
            $valid = 'create' === $action ? $actor === $to : \in_array($actor, [$from, $to], strict: true);
            if (!$valid) {
                throw $pending['statement']->error('Lifecycle directive does not match its associated message');
            }
            $this->diagram->lifetimes[$actor] ??= ['create' => null, 'destroy' => null];
            $this->diagram->lifetimes[$actor][$action] = $index;
        }
        $this->pending = [];
    }

    public function finish(): void
    {
        $pending = \array_first($this->pending);
        if (null !== $pending) {
            throw $pending['statement']->error('Lifecycle directive requires an associated message');
        }
    }
}
