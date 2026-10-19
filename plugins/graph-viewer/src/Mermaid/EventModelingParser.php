<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Completed declarations commit incrementally; duplicate frame IDs are rejected at their original line. */
final class EventModelingParser
{
    private readonly EventModelingDiagram $diagram;
    private bool $header = false;
    /** @var list<MermaidStatement> */
    private array $pending = [];
    private int $count = 0;

    public function __construct()
    {
        $this->diagram = new EventModelingDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if ('' === $statement->text || !$this->header) {
            if ('' !== $statement->text) {
                if ('eventmodeling' !== $statement->text) {
                    throw $statement->error('Expected eventmodeling header');
                }
                $this->header = true;
                $this->diagram->origin = $statement->line;
            }

            return;
        }
        $match = [];
        $metadata = 1 === \preg_match('/^[\t ]+(title|accTitle|accDescr)\b/', $statement->text, $match);
        $start = \in_array($statement->text, EventModelingLexer::TOP, strict: true);
        if ($metadata || $start) {
            $this->declaration();
        }
        if ($metadata) {
            $this->diagram->metadata[$match[1]] = $statement;

            return;
        }
        if ([] === $this->pending && !$start) {
            throw $statement->error('Expected an event-modeling declaration');
        }
        if (\count($this->pending) >= 8192) {
            throw $statement->error('Event-modeling declaration exceeds the field limit');
        }
        $this->pending[] = $statement;
    }

    private function declaration(): void
    {
        if ([] === $this->pending) {
            return;
        }
        $statement = $this->pending[0];
        if (++$this->count > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Event-modeling declaration limit exceeded');
        }
        $cursor = new EventModelingCursor($this->pending);
        $this->pending = [];
        $kind = $cursor->tokens->take();
        match ($kind) {
            'entity' => $this->entity($cursor, $statement),
            'data' => $this->data($cursor, $statement),
            'note' => $this->note($cursor, $statement),
            'gwt' => $this->gwt($cursor, $statement),
            default => $this->frame($cursor, $kind, $statement),
        };
        $cursor->tokens->finish();
    }

    private function frame(EventModelingCursor $cursor, string $kind, MermaidStatement $statement): void
    {
        $name = $cursor->frame();
        if (\array_key_exists($name, $this->diagram->frameIds)) {
            throw $statement->error('Duplicate event modeling frame ID "'.$name.'"');
        }
        $role = $cursor->role();
        $entity = $cursor->qualified();
        $sources = [];
        while ($cursor->tokens->consume('->>')) {
            $sources[] = $cursor->frame();
        }
        $reference = null;
        if ($cursor->tokens->consume('[[')) {
            $reference = $cursor->identifier();
            $cursor->tokens->expect(']]');
        }
        $data = '' === $cursor->tokens->peek() ? ['type' => null, 'value' => null] : $cursor->data('inline');
        $this->diagram->frameIds[$name] = true;
        $this->diagram->frames[] = [
            'name' => $name,
            'modelEntityType' => $role,
            'entityIdentifier' => $entity,
            'sourceFrames' => $sources,
            'dataReference' => $reference,
            'dataType' => $data['type'],
            'dataInlineValue' => $data['value'],
            'reset' => \in_array($kind, ['rf', 'resetframe'], strict: true),
            'statement' => $statement,
        ];
    }

    private function entity(EventModelingCursor $cursor, MermaidStatement $statement): void
    {
        $this->diagram->modelEntities[] = ['name' => $cursor->qualified(), 'statement' => $statement];
    }

    private function data(EventModelingCursor $cursor, MermaidStatement $statement): void
    {
        $name = $cursor->identifier();
        $data = $cursor->data('block');
        $this->diagram->dataEntities[] = ['name' => $name, 'dataType' => $data['type'], 'dataBlockValue' => $data['value'], 'statement' => $statement];
    }

    private function note(EventModelingCursor $cursor, MermaidStatement $statement): void
    {
        $frame = $cursor->frame();
        $data = $cursor->data('block');
        $this->diagram->noteEntities[] = ['sourceFrame' => $frame, 'dataType' => $data['type'], 'dataBlockValue' => $data['value'], 'statement' => $statement];
    }

    private function gwt(EventModelingCursor $cursor, MermaidStatement $statement): void
    {
        $frame = $cursor->frame();
        $cursor->tokens->expect('given');
        $given = self::criteria($cursor);
        $when = $cursor->tokens->consume('when') ? self::criteria($cursor) : [];
        $cursor->tokens->expect('then');
        $then = self::criteria($cursor);
        $this->diagram->gwtEntities[] = ['sourceFrame' => $frame, 'givenStatements' => $given, 'whenStatements' => $when, 'thenStatements' => $then, 'statement' => $statement];
    }

    /** @return non-empty-list<array{entityIdentifier: string, role: string}> */
    private static function criteria(EventModelingCursor $cursor): array
    {
        $values = [];
        do {
            $role = $cursor->role();
            $values[] = ['entityIdentifier' => $cursor->identifier(), 'role' => $role];
        } while (\in_array($cursor->tokens->peek(), EventModelingLexer::ROLES, strict: true));

        return $values;
    }

    public function finish(): EventModelingDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected eventmodeling header', offset: 0, sourceLine: 0, column: 0);
        }
        $this->declaration();

        return $this->diagram;
    }
}
