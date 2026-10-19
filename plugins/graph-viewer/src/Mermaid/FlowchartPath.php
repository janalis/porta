<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** A node group followed by zero or more labeled links and node groups. */
final class FlowchartPath
{
    private int $position = 0;
    private MermaidStatement $statement;
    private ?MermaidMarkdown $nodeMarkdown = null;
    private ?MermaidMarkdown $edgeMarkdown = null;

    /** @param list<string> $groups */
    public function __construct(
        private readonly MermaidGraph $graph,
        private readonly array $groups,
    ) {}

    /** @return list<string> */
    public function parse(MermaidStatement $statement): array
    {
        $this->statement = $statement;

        return $this->connections($this->nodes());
    }

    /** @param list<string> $left
     * @return list<string>
     */
    public function continue(MermaidStatement $statement, array $left): array
    {
        $this->statement = $statement;
        if ([] === $left) {
            throw $statement->error('A continued flowchart link requires a preceding node');
        }

        return $this->connections($left);
    }

    /** @param list<string> $left
     * @return list<string>
     */
    private function connections(array $left): array
    {
        $statement = $this->statement;
        while ('' !== $this->remaining()) {
            [$arrow, $label, $id] = $this->link();
            $right = $this->nodes();
            foreach ($left as $from) {
                foreach ($right as $to) {
                    if (null !== $id && $to === $right[0] && !\array_key_exists($id, $this->graph->edgeIds) && $from === $left[\count($left) - 1]) {
                        $this->graph->edgeIds[$id] = \count($this->graph->edges);
                        $this->graph->edgeStyles->identify(\count($this->graph->edges), $id);
                    }
                    if (null !== $this->edgeMarkdown) {
                        $this->graph->markdown['edge:'.\count($this->graph->edges)] = $this->edgeMarkdown;
                    }
                    $this->graph->edge($from, $to, $label, $arrow, $statement);
                }
            }
            $left = $right;
        }

        return $left;
    }

    /** @return list<string> */
    private function nodes(): array
    {
        $nodes = [$this->node()];
        while (\str_starts_with($this->remaining(), '&')) {
            ++$this->position;
            $nodes[] = $this->node();
        }

        return $nodes;
    }

    private function node(): string
    {
        $remaining = $this->remaining();
        $match = [];
        if (1 !== \preg_match('/^[\p{L}\p{N}_](?:(?!--|-\\.)[\p{L}\p{N}_.-])*/u', $remaining, $match)) {
            throw $this->statement->error('Expected a node identifier', $this->position);
        }
        // Compact links end identifiers before their first operator.
        $id = \rtrim($match[0], characters: '-');
        $this->position += \strlen($id);
        if (\array_key_exists($id, $this->graph->edgeIds) && \str_starts_with($this->remaining(), '@{')) {
            // Pinned flowDb applies metadata to an earlier edge ID instead of creating a vertex.
            $this->position += 2;
            $values = $this->values($this->until('}'));
            $this->graph->edgeStyles->interpolate($this->graph->edgeIds[$id], $values['curve'] ?? '');

            return $id;
        }
        $label = $this->shape($id);
        if (\str_starts_with($this->remaining(), ':::')) {
            $this->position += 3;
            $class = [];
            if (1 !== \preg_match('/^[\w-]+/u', $this->remaining(), $class)) {
                throw $this->statement->error('Expected a class name', $this->position);
            }
            $this->graph->styles->assign($id, $class[0], $this->statement);
            $this->position += \strlen($class[0]);
        }
        $this->graph->node($id, $label, $this->statement, $this->groups);
        if (null !== $this->nodeMarkdown) {
            $this->graph->markdown['node:'.$id] = $this->nodeMarkdown;
            $this->nodeMarkdown = null;
        }

        return $id;
    }

    private function shape(string $id): ?string
    {
        $remaining = $this->remaining();
        $delimiters = [
            '@{' => '}',
            '(((' => ')))',
            '[[' => ']]',
            '[(' => ')]',
            '([' => '])',
            '((' => '))',
            '{{' => '}}',
            '[' => ']',
            '(' => ')',
            '{' => '}',
            '>' => ']',
        ];
        $open = \array_find_key($delimiters, static fn (string $close, string $open): bool => \str_starts_with($remaining, $open));
        if (null === $open) {
            return null;
        }
        $close = $delimiters[$open];
        $this->position += \strlen($open);
        $body = $this->until($close);
        $this->graph->shapes[$id] = match ($open) {
            '(((' => 'doublecircle',
            '[[' => 'subroutine',
            '[(' => 'cylinder',
            '([' => 'stadium',
            '((' => 'circle',
            '{{' => 'hexagon',
            '(' => 'rounded',
            '{' => 'diamond',
            '>' => 'asymmetric',
            '[' => $this->slantedShape(\trim($body)),
            default => 'rect',
        };

        $raw = '[' === $open ? \trim($body, characters: '/\\') : $body;
        $this->nodeMarkdown = '@{' === $open ? null : self::markdown($raw);

        return '@{' === $open ? $this->attributes($body, $id) : self::label($raw);
    }

    private function slantedShape(string $body): string
    {
        return match (true) {
            \str_starts_with($body, '/') && \str_ends_with($body, '/') => 'lean-r',
            \str_starts_with($body, '\\') && \str_ends_with($body, '\\') => 'lean-l',
            \str_starts_with($body, '/') && \str_ends_with($body, '\\') => 'trap-b',
            \str_starts_with($body, '\\') && \str_ends_with($body, '/') => 'trap-t',
            default => 'rect',
        };
    }

    /** @return array{string, string, ?string} */
    private function link(): array
    {
        $remaining = $this->remaining();
        $id = [];
        $match = [];
        $edgeId = null;
        // Only the last source and first target receive an authored ID in a grouped link.
        if (1 === \preg_match('/^[\w-]+@/u', $remaining, $id)) {
            $edgeId = \substr($id[0], offset: 0, length: -1);
            $this->position += \strlen($id[0]);
            $remaining = $this->remaining();
        }
        if (1 !== \preg_match('/^(?:[<ox])?(?:-{2,}|={2,}|-?\.+-?|~{3,})(?:[>ox])?/', $remaining, $match)) {
            throw $this->statement->error('Expected a flowchart link', $this->position);
        }
        $arrow = $match[0];
        $this->position += \strlen($arrow);
        $label = '';
        $this->edgeMarkdown = null;
        if (\str_starts_with($this->remaining(), '|')) {
            ++$this->position;
            $raw = $this->until('|');
            $this->edgeMarkdown = self::markdown($raw);

            return [$arrow, self::label($raw), $edgeId];
        }
        if (\in_array(\ltrim($arrow, characters: '<ox'), ['--', '==', '-.'], strict: true)) {
            $labelMatch = [];
            if (1 === \preg_match('/^("(?:\\\\.|[^"\\\\])*"|.*?)\s*([<ox]?(?:--+[-ox>]?|==+[=ox>]?|-?\.+-[ox>]?))/s', $this->remaining(), $labelMatch)) {
                $label = self::label($labelMatch[1]);
                $this->edgeMarkdown = self::markdown($labelMatch[1]);
                $prefix = \in_array($arrow[0], ['<', 'o', 'x'], strict: true) ? $arrow[0] : '';
                $arrow = $prefix.$labelMatch[2];
                $this->position += \strlen($labelMatch[0]);
            }
        }

        return [$arrow, $label, $edgeId];
    }

    private function until(string $close): string
    {
        $start = $this->position;
        $quote = new MermaidQuotedText();
        $length = \strlen($this->statement->text);
        while ($this->position < $length) {
            $char = $this->statement->text[$this->position];
            if (!$quote->quoted && \substr($this->statement->text, $this->position, \strlen($close)) === $close) {
                $body = \substr($this->statement->text, $start, $this->position - $start);
                $this->position += \strlen($close);

                return $body;
            }
            $quote->advance($char);
            ++$this->position;
        }
        throw $this->statement->error('Unclosed '.$close, $start);
    }

    private function attributes(string $body, string $id): ?string
    {
        $values = $this->values($body);
        if (\array_key_exists('shape', $values) && !FlowchartShapes::known($values['shape'])) {
            throw $this->statement->error('Unknown flowchart shape '.$values['shape']);
        }
        $label = $values['label'] ?? null;
        $this->graph->shapes[$id] = $values['shape'] ?? 'rect';
        if (\array_key_exists('icon', $values) || \array_key_exists('img', $values)) {
            [$this->graph->shapes[$id], $this->graph->sizes[$id]] = FlowchartMedia::node($values, $label ?? '');
            $label ??= '';
        }

        return $label;
    }

    /** @return array<string, string> */
    private function values(string $body): array
    {
        $properties = self::properties($body);
        $values = [];
        foreach ($properties as $property) {
            $match = [];
            if (
                1 !== \preg_match('/^\s*([\w-]+)\s*:\s*("(?:\\\\.|[^"\\\\])*"|[^{},"]+)\s*$/s', $property, $match)
                || (!\str_starts_with($match[2], '"') && 1 === \preg_match('/\s+[\w-]+\s*:/', $match[2]))
            ) {
                throw $this->statement->error('Invalid shape property', $this->position - \strlen($body) - 1);
            }
            $values[$match[1]] = self::label($match[2]);
        }

        return $values;
    }

    /** @return list<string> */
    private static function properties(string $body): array
    {
        $quote = new MermaidQuotedText();
        $start = 0;
        $properties = [];
        $length = \strlen($body);
        for ($i = 0; $i < $length; ++$i) {
            $quote->advance($body[$i]);
            if (',' === $body[$i] && !$quote->quoted) {
                $properties[] = \substr($body, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $properties[] = \substr($body, $start);

        return $properties;
    }

    private function remaining(): string
    {
        $text = \substr($this->statement->text, $this->position);
        $trimmed = \ltrim($text);
        $this->position += \strlen($text) - \strlen($trimmed);

        return $trimmed;
    }

    /** A backtick-quoted label as Markdown, else null. */
    public static function markdown(string $raw): ?MermaidMarkdown
    {
        $body = MermaidMarkdown::body($raw);

        return null === $body ? null : MermaidMarkdown::parse($body);
    }

    public static function label(string $text): string
    {
        $markdown = self::markdown($text);
        if (null !== $markdown) {
            return MermaidText::decode($markdown->plain());
        }
        $text = \trim($text);
        if (\str_starts_with($text, '"') && \str_ends_with($text, '"')) {
            $text = \strtr(\substr($text, offset: 1, length: -1), ['\\"' => '"', '\\\\' => '\\']);
        }
        $text = \trim($text, characters: '`');

        return MermaidText::decode($text);
    }
}
