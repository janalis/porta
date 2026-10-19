<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Pinned sequence syntax. Unsupported statements fail with their source location. */
final class SequenceParser
{
    public const array ARROWS = [
        '<<-->>',
        '<<->>',
        '-->>',
        '->>',
        '-->',
        '->',
        '--x',
        '-x',
        '--)',
        '-)',
        '--|\\',
        '-|\\',
        '--|/',
        '-|/',
        '--\\\\',
        '-\\\\',
        '--//',
        '-//',
        '/|--',
        '/|-',
        '\\|--',
        '\\|-',
        '//--',
        '//-',
        '\\\\--',
        '\\\\-',
    ];

    private readonly SequenceDiagram $diagram;
    private readonly SequenceControls $controls;
    private readonly SequenceActivities $activities;
    private readonly SequenceLifetimes $lifetimes;
    private readonly SequenceGroups $groups;
    private readonly SequenceMenus $menus;
    private bool $header = false;
    private bool $numbered = false;
    private int $number = 100;
    private int $increment = 100;

    public function __construct(
        private readonly MermaidConfiguration $configuration = new MermaidConfiguration(),
    ) {
        $this->diagram = new SequenceDiagram();
        $this->controls = new SequenceControls($this->diagram);
        $this->activities = new SequenceActivities($this->diagram);
        $this->lifetimes = new SequenceLifetimes($this->diagram);
        $this->groups = new SequenceGroups($this->diagram, $configuration);
        $this->menus = new SequenceMenus($this->diagram);
    }

    public function accept(MermaidStatement $statement): void
    {
        if ($this->groups->active()) {
            $this->groups->accept($statement);
        }
        $match = [];
        match (true) {
            !$this->header => $this->header($statement),
            1 === \preg_match('/^(title:?)\s+(.+)$/i', $statement->text, $match) => $this->metadata('title', $match[2], $statement),
            1 === \preg_match('/^(accTitle|accDescr)\s*:\s*(.*)$/i', $statement->text, $match) => $this->metadata(\strtolower($match[1]), $match[2], $statement),
            1 === \preg_match('/^accDescr\s*\{([^}]*)\}$/i', $statement->text, $match) => $this->metadata('accdescr', $match[1], $statement),
            1 === \preg_match('/^create\s+(participant|actor)\s+([^@]+?(?:@\{.*\})?)(?:\s+as\s+(.+))?$/is', $statement->text, $match) => $this->create($match, $statement),
            1 === \preg_match('/^destroy\s+(.+)$/i', $statement->text, $match) => $this->destroy($match[1], $statement),
            1 === \preg_match('/^box(?:\s+(.*))?$/i', $statement->text, $match) => $this->groups->start(\trim($match[1] ?? ''), $statement),
            'end' === \strtolower($statement->text) && $this->groups->active() => $this->groups->end(),
            'end' === \strtolower($statement->text) => $this->controls->end($statement),
            1 === \preg_match('/^(loop|rect|opt|alt|par_over|par|critical|break)(?:\s+(.*))?$/i', $statement->text, $match) => $this->controls->start(
                \strtolower($match[1]),
                $this->text($match[2] ?? ''),
                $statement,
            ),
            1 === \preg_match('/^(else|and|option)(?:\s+(.*))?$/i', $statement->text, $match) => $this->controls->branch(\strtolower($match[1]), $this->text($match[2] ?? ''), $statement),
            1 === \preg_match('/^(activate|deactivate)\s+(.+)$/i', $statement->text, $match) => $this->activities->change($this->id(\trim($match[2]), $statement), \strtolower($match[1]), $statement),
            1 === \preg_match('/^(participant|actor)\s+([^@]+?(?:@\{.*\})?)(?:\s+as\s+(.+))?$/is', $statement->text, $match) => $this->declaration($match, $statement),
            1 === \preg_match('/^(link|links|properties|details)\s+([^:]+):(.*)$/is', $statement->text, $match) => $this->menu($match, $statement),
            1 === \preg_match('/^autonumber(?:\s+(off|(?:\d+(?:\.\d{1,2})?|\.\d{1,2}))(?:\s+(\d+(?:\.\d{1,2})?|\.\d{1,2}))?)?$/i', $statement->text, $match) => $this->autonumber($match, $statement),
            1 === \preg_match('/^Note\s+(left of|right of|over)\s+([^:]+):(.*)$/i', $statement->text, $match) => $this->note($match, $statement),
            default => $this->message($statement),
        };
    }

    /** @param array<array-key, string> $match */
    private function menu(array $match, MermaidStatement $statement): void
    {
        $actor = $this->id(\trim($match[2]), $statement);
        $this->participant($actor, null, $statement);
        $this->menus->accept(\strtolower($match[1]), $actor, $match[3], $statement);
    }

    private function metadata(string $kind, string $label, MermaidStatement $statement): void
    {
        $this->diagram->metadata[$kind] = ['label' => $this->text($label), 'origin' => $statement->line];
    }

    /** @param array<array-key, string> $match */
    private function declaration(array $match, MermaidStatement $statement): void
    {
        [$id, $alias, $type] = SequenceConfiguration::parse(\trim($match[2]), $statement, $match[3] ?? null);
        $id = $this->id($id, $statement);
        $label = SequenceText::participant($id, $alias, $match[3] ?? null, $this->configuration);
        $this->declaredType($id, $type, $match[1], $label);
        $this->participant($id, $label, $statement);
    }

    private function declaredType(string $id, ?string $type, string $keyword, ?string $label): void
    {
        if (null === $label && \array_key_exists($id, $this->diagram->participants)) {
            return;
        }
        $type ??= 'actor' === \strtolower($keyword) ? 'actor' : null;
        if (null === $type) {
            unset($this->diagram->participantTypes[$id]);

            return;
        }
        $this->diagram->participantTypes[$id] = $type;
    }

    /** @param array<array-key, string> $match */
    private function create(array $match, MermaidStatement $statement): void
    {
        [$id, $alias, $type] = SequenceConfiguration::parse($match[2], $statement, $match[3] ?? null);
        $actor = $this->id($id, $statement);
        $this->lifetimes->declare('create', $actor, $statement);
        $label = SequenceText::participant($actor, $alias, $match[3] ?? null, $this->configuration);
        $this->declaredType($actor, $type, $match[1], $label);
        $this->participant($actor, $label, $statement);
    }

    private function destroy(string $id, MermaidStatement $statement): void
    {
        $id = $this->id(\trim($id), $statement);
        $this->groups->participant($id, $statement);
        $this->lifetimes->declare('destroy', $id, $statement);
    }

    private function header(MermaidStatement $statement): void
    {
        if ('sequencediagram' !== \strtolower($statement->text)) {
            throw $statement->error('Expected sequenceDiagram');
        }
        $this->header = true;
    }

    /** @param array<array-key, string> $match */
    private function autonumber(array $match, MermaidStatement $statement): void
    {
        if (null !== ($match[2] ?? null) && 'off' === \strtolower($match[1] ?? '')) {
            throw $statement->error('Autonumber off takes no increment');
        }
        $this->numbered = 'off' !== \strtolower($match[1] ?? '');
        if (null !== ($match[1] ?? null) && $this->numbered) {
            $this->number = $this->hundredths($match[1], $statement);
            $this->increment = $this->hundredths($match[2] ?? '1', $statement);
        }
    }

    /** Store hundredths as integers: fractional increments never accumulate rounding errors. */
    private function hundredths(string $text, MermaidStatement $statement): int
    {
        $parts = \explode('.', $text, limit: 2);
        $digits = \ltrim($parts[0], characters: '0');
        $whole = (int) $digits;
        if ($whole > 1_000_000_000_000 || \strlen($digits) > 13) {
            throw $statement->error('Autonumber exceeds the numeric limit');
        }

        // The event limit bounds the sum to fewer than 4098 * 10^14, within a 64-bit integer.
        return ($whole * 100) + (int) \str_pad($parts[1] ?? '', length: 2, pad_string: '0');
    }

    private function numberText(): string
    {
        $text = (string) \intdiv($this->number, num2: 100);
        $fraction = $this->number % 100;
        if (0 !== $fraction) {
            $text .= '.'.\rtrim(\str_pad((string) $fraction, length: 2, pad_string: '0', pad_type: \STR_PAD_LEFT), characters: '0');
        }

        return $text;
    }

    /** @param array<array-key, string> $match */
    private function note(array $match, MermaidStatement $statement): void
    {
        $actors = \array_map(fn (string $actor): string => $this->id(\trim($actor), $statement), \explode(',', $match[2]));
        if (\count($actors) > ('over' === \strtolower($match[1]) ? 2 : 1)) {
            throw $statement->error('Invalid note participant list');
        }
        foreach ($actors as $actor) {
            $this->participant($actor, null, $statement);
        }
        $this->diagram->event($actors[0], $actors[\count($actors) - 1], $this->text($match[3]), '', \strtolower($match[1]), $statement, kind: 'note');
    }

    private function message(MermaidStatement $statement): void
    {
        $match = [];
        $arrows = \implode('|', \array_map(static fn (string $arrow): string => \preg_quote($arrow, delimiter: '~'), self::ARROWS));
        if (1 !== \preg_match('~^(.+?)(\(\))?('.$arrows.')(\(\)|[+-])?\s*([^:]+):(.*)$~', $statement->text, $match)) {
            throw $statement->error('Expected a sequence participant, message, note or autonumber statement');
        }
        $from = $this->id(\trim($match[1]), $statement);
        $to = $this->id(\trim($match[5]), $statement);
        $this->participant($from, null, $statement);
        $this->participant($to, null, $statement);
        $label = $this->text($match[6]);
        $shortcut = \in_array($match[4], ['+', '-'], strict: true) ? $match[4] : '';
        if ('' !== $shortcut && '' !== $match[2]) {
            throw $statement->error('Activation shortcuts cannot combine with a central sender');
        }
        if ($this->numbered || true === $this->configuration->sequenceNumbers) {
            $label = $this->numberText().'. '.$label;
        }
        $this->number += $this->increment;
        $index = $this->diagram->event($from, $to, $label, $match[2].$match[3].('' === $shortcut ? $match[4] : ''), $shortcut, $statement);
        $this->lifetimes->message($from, $to, $index);
        if ('' !== $shortcut) {
            $this->activities->change('+' === $shortcut ? $to : $from, '+' === $shortcut ? 'activate' : 'deactivate', $statement, $index);
        }
    }

    public function finish(): SequenceDiagram
    {
        if (!$this->header) {
            throw new MermaidSyntaxError('Expected sequenceDiagram', offset: 0, sourceLine: 0, column: 0);
        }
        $this->groups->finish();
        $this->controls->finish();
        $this->activities->finish();
        $this->lifetimes->finish();

        return $this->diagram;
    }

    private function participant(string $id, ?string $label, MermaidStatement $statement): void
    {
        $id = $this->id($id, $statement);
        $this->groups->participant($id, $statement);
        if (!\array_key_exists($id, $this->diagram->participants)) {
            if (\count($this->diagram->participants) >= MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the participant limit');
            }
            $this->diagram->participants[$id] = ['label' => $label ?? SequenceText::decoded($id, $this->configuration->wrapping()), 'origin' => $statement->line];

            return;
        }
        if (null !== $label) {
            $this->diagram->participants[$id] = ['label' => $label, 'origin' => $statement->line];
        }
    }

    private function id(string $id, MermaidStatement $statement): string
    {
        if ('' === $id || \str_starts_with($id, '-') || \str_ends_with($id, '-') || 1 === \preg_match('~[<>:(),;@+\\\\/]~', $id)) {
            throw $statement->error('Invalid sequence participant');
        }

        return $id;
    }

    private function text(string $text): string
    {
        return SequenceText::parse($text, $this->configuration);
    }
}
