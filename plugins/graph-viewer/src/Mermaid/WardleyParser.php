<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Pipeline and link references are resolved after all authored coordinates are known. */
final class WardleyParser
{
    private readonly WardleyDiagram $diagram;
    private ?MermaidStatement $header = null;
    private ?int $pipeline = null;
    private int $count = 0;
    /** @var array<array-key, true> */
    private array $anchors = [];

    public function __construct()
    {
        $this->diagram = new WardleyDiagram();
    }

    public function accept(MermaidStatement $statement): void
    {
        if (null === $this->header) {
            if ('wardley-beta' !== $statement->text) {
                throw $statement->error('Expected wardley-beta');
            }
            $this->header = $statement;
            $this->diagram->origin = $statement->line;

            return;
        }
        if (++$this->count > MermaidGraph::MAX_ITEMS) {
            throw $statement->error('Wardley map exceeds the declaration limit');
        }
        if (null !== $this->pipeline) {
            $this->pipelineItem($statement);

            return;
        }
        $match = [];
        match (true) {
            1 === \preg_match('/^(?:title\b|accTitle\s*:|accDescr\s*[:{])/', $statement->text) => $this->diagram->metadata[] = [
                'text' => MermaidText::decode($statement->text),
                'origin' => $statement->line,
            ],
            1 === \preg_match('/^size\s*\[\s*(\d+)\s*,\s*(\d+)\s*\]$/', $statement->text, $match) => $this->size($match[1], $match[2], $statement),
            1 === \preg_match('/^(anchor|component)\s/', $statement->text) => $this->node($statement),
            1 === \preg_match('/^pipeline\s+('.WardleyLexer::NAME.')\s*\{$/', $statement->text, $match) => $this->open($match[1], $statement),
            1 === \preg_match('/^evolve\s+('.WardleyLexer::NAME.')\s+('.WardleyLexer::NUMBER.')$/', $statement->text, $match) => $this->diagram->trends[self::name($match[1])] = [
                'name' => self::name($match[1]),
                'target' => self::coordinate($match[2], $statement),
                'origin' => $statement->line,
            ],
            \str_starts_with($statement->text, 'evolution ') => $this->stages($statement),
            1 === \preg_match('/^(note|annotations?|accelerator|deaccelerator)\b/', $statement->text) => $this->annotation($statement),
            default => $this->link($statement),
        };
    }

    private function size(string $width, string $height, MermaidStatement $statement): void
    {
        $w = (int) $width;
        $h = (int) $height;
        if ($w < 100 || $h < 100 || $w > 10_000 || $h > 10_000 || \strlen($width) > 5 || \strlen($height) > 5) {
            throw $statement->error('Wardley canvas dimensions must be between 100 and 10000');
        }
        $this->diagram->width = (int) $width / 10;
        $this->diagram->height = (int) $height / 10;
    }

    public static function coordinate(string $value, MermaidStatement $statement): float
    {
        if (!\is_numeric($value)) {
            throw $statement->error('Wardley coordinates must be numeric');
        }
        $number = (float) $value;
        if (!\is_finite($number) || $number < 0 || $number > 100) {
            throw $statement->error('Wardley coordinates must be between 0 and 1, or 0 and 100 percent');
        }

        return $number <= 1 ? $number : $number / 100;
    }

    public static function name(string $name): string
    {
        $name = \trim($name);

        return \str_starts_with($name, '"') || \str_starts_with($name, "'") ? MermaidQuotedString::decode($name) : $name;
    }

    private function node(MermaidStatement $statement): void
    {
        $match = [];
        if (1 !== \preg_match('~^(anchor|component)\s+('.WardleyLexer::NAME.')\s*\[\s*('.WardleyLexer::NUMBER.')\s*,\s*('.WardleyLexer::NUMBER.')\s*\](.*)$~s', $statement->text, $match)) {
            throw $statement->error('Wardley node requires visibility and evolution coordinates');
        }
        $options = $this->options($match[5], $statement);
        if ('anchor' === $match[1] && '' !== \trim($match[5])) {
            throw $statement->error('Wardley anchors do not accept component decorators');
        }
        $name = self::name($match[2]);
        $visibility = self::coordinate($match[3], $statement);
        $evolution = self::coordinate($match[4], $statement);
        if ('anchor' === $match[1]) {
            $this->anchors[$name] = true;
        }
        // Pinned population visits all anchors before components, irrespective of source order.
        if ('anchor' === $match[1] && 'component' === ($this->diagram->nodes[$name]['kind'] ?? '')) {
            return;
        }
        $this->diagram->nodes[$name] = [
            'label' => $name,
            'visibility' => $visibility,
            'evolution' => $evolution,
            'origin' => $statement->line,
            'kind' => $match[1],
            ...$options,
        ];
    }

    /** @return array{dx: float, dy: float, strategy: string, inertia: bool} */
    private function options(string $text, MermaidStatement $statement): array
    {
        $match = [];
        if (1 !== \preg_match('/^\s*(?:label\s*\[\s*(-?\d+)\s*,\s*(-?\d+)\s*\])?\s*(?:\((build|buy|outsource|market)\))?\s*(?:(inertia)|\((inertia)\))?\s*$/', $text, $match)) {
            throw $statement->error('Invalid Wardley component decorator');
        }
        $dx = $match[1] ?? '';
        $dy = $match[2] ?? '';
        if (\strlen($dx) > 6 || \strlen($dy) > 6) {
            throw $statement->error('Wardley label offset exceeds its numeric limit');
        }

        return [
            'dx' => '' === $dx ? 3.0 : (int) $dx / 10.0,
            'dy' => '' === $dy ? -2.0 : (int) $dy / 10.0,
            'strategy' => $match[3] ?? '',
            'inertia' => '' !== ($match[4] ?? '') || '' !== ($match[5] ?? ''),
        ];
    }

    private function open(string $name, MermaidStatement $statement): void
    {
        $this->pipeline = \count($this->diagram->pendingPipelines);
        $this->diagram->pendingPipelines[] = ['parent' => self::name($name), 'items' => [], 'statement' => $statement];
    }

    private function pipelineItem(MermaidStatement $statement): void
    {
        $index = $this->pipeline ?? 0;
        if ('}' === $statement->text) {
            if ([] === $this->diagram->pendingPipelines[$index]['items']) {
                throw $statement->error('Wardley pipeline requires a component');
            }
            $this->pipeline = null;

            return;
        }
        $match = [];
        if (1 !== \preg_match('~^component\s+('.WardleyLexer::NAME.')\s*\[\s*('.WardleyLexer::NUMBER.')\s*\](.*)$~s', $statement->text, $match)) {
            throw $statement->error('Expected a one-coordinate pipeline component or closing brace');
        }
        $options = $this->options($match[3], $statement);
        if ('' !== $options['strategy'] || $options['inertia']) {
            throw $statement->error('Pipeline components accept label offsets only');
        }
        $this->diagram->pendingPipelines[$index]['items'][] = [
            'name' => self::name($match[1]),
            'evolution' => self::coordinate($match[2], $statement),
            'dx' => $options['dx'],
            'dy' => $options['dy'],
            'origin' => $statement->line,
        ];
    }

    private function stages(MermaidStatement $statement): void
    {
        $text = \substr($statement->text, offset: 10);
        $parts = [];
        $quoted = new MermaidQuotedText();
        $start = 0;
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $quoted->advance($text[$i]);
            if (!$quoted->quoted && '->' === \substr($text, $i, length: 2)) {
                $parts[] = \trim(\substr($text, $start, length: $i - $start));
                ++$i;
                $start = $i + 1;
            }
        }
        $parts[] = \trim(\substr($text, $start));
        if (\count($parts) < 2) {
            throw $statement->error('Wardley evolution requires at least two stages');
        }
        $stages = [];
        foreach ($parts as $part) {
            $match = [];
            if (1 !== \preg_match('~^('.WardleyLexer::NAME.')(?:\s*@\s*('.WardleyLexer::NUMBER.'))?(?:\s*/\s*('.WardleyLexer::NAME.'))?$~', $part, $match)) {
                throw $statement->error('Invalid Wardley evolution stage');
            }
            $label = self::name($match[1]).('' !== ($match[3] ?? '') ? ' / '.self::name($match[3]) : '');
            $stages[] = ['label' => $label, 'boundary' => '' !== ($match[2] ?? '') ? self::coordinate($match[2], $statement) : null];
        }
        $this->diagram->stages = $stages;
    }

    private function annotation(MermaidStatement $statement): void
    {
        $text = $statement->text;
        $match = [];
        if (1 === \preg_match('~^annotation\s+(\d+)\s*,\s*\[\s*(\d+(?:\.\d+)?)\s*,\s*(\d+(?:\.\d+)?)\s*\]\s*('.MermaidQuotedString::PATTERN.')$~s', $text, $match)) {
            $this->diagram->annotations[] = [
                'kind' => 'annotation',
                'label' => $match[1].'. '.self::name($match[4]),
                'visibility' => self::coordinate($match[2], $statement),
                'evolution' => self::coordinate($match[3], $statement),
                'origin' => $statement->line,
            ];

            return;
        }
        if (1 !== \preg_match('~^(note|annotations|accelerator|deaccelerator)\s*(?:('.WardleyLexer::NAME.')\s*)?\[\s*(\d+(?:\.\d+)?)\s*,\s*(\d+(?:\.\d+)?)\s*\]$~s', $text, $match)) {
            throw $statement->error('Invalid Wardley annotation or note');
        }
        if ('note' === $match[1] && !\str_starts_with($match[2], '"') && !\str_starts_with($match[2], "'")) {
            throw $statement->error('Wardley note text must be quoted');
        }
        if (('annotations' === $match[1] && '' !== $match[2]) || ('' === $match[2] && \in_array($match[1], ['accelerator', 'deaccelerator'], strict: true))) {
            throw $statement->error('Invalid Wardley annotation name');
        }
        $this->diagram->annotations[] = [
            'kind' => $match[1],
            'label' => self::name($match[2]),
            'visibility' => self::coordinate($match[3], $statement),
            'evolution' => self::coordinate($match[4], $statement),
            'origin' => $statement->line,
        ];
    }

    private function link(MermaidStatement $statement): void
    {
        $match = [];
        if (
            1 !== \preg_match(
                '~^('.WardleyLexer::NAME.')[\t ]*(\+<>|\+>|\+<)?[\t ]*(->|-->|-\.->|>|\+\'[^\']*\'(?:<>|<|>))?[\t ]*('.WardleyLexer::NAME.')[\t ]*(\+<>|\+>|\+<)?(?:[\t ]*;(.*))?$~s',
                $statement->text,
                $match,
            )
        ) {
            throw $statement->error('Invalid Wardley dependency');
        }
        if (
            '' === $match[2]
            && '' === $match[3]
            && '' === ($match[5] ?? '')
            && !\str_starts_with($match[1], '"')
            && !\str_starts_with($match[1], "'")
            && !\str_starts_with($match[4], '"')
            && !\str_starts_with($match[4], "'")
        ) {
            throw $statement->error('Unquoted Wardley dependency requires an operator');
        }
        $arrow = $match[3];
        $flow = '' !== $match[2] ? $match[2] : $match[5] ?? '';
        $label = \array_key_exists(6, $match) ? ';'.$match[6] : '';
        $custom = [];
        if (1 === \preg_match('/^\+\'([^\']*)\'(<|>|<>)$/', $arrow, $custom)) {
            $label = $custom[1];
            $flow = '' === $flow ? $custom[2] : $flow;
        }
        $flow = match ($flow) {
            '+<>', '<>' => 'both',
            '+<', '<' => 'backward',
            '+>', '>' => 'forward',
            default => 'none',
        };
        $this->diagram->links[] = [
            'from' => self::name($match[1]),
            'to' => self::name($match[4]),
            'flow' => $flow,
            'label' => \trim($label),
            'dashed' => '-.->' === $arrow,
            'origin' => $statement->line,
        ];
    }

    public function finish(): WardleyDiagram
    {
        if (null === $this->header) {
            throw new MermaidSyntaxError('Expected wardley-beta', offset: 0, sourceLine: 0, column: 0);
        }
        if (null !== $this->pipeline) {
            throw $this->diagram->pendingPipelines[$this->pipeline]['statement']->error('Unclosed Wardley pipeline');
        }
        $ordered = [];
        foreach ($this->anchors as $name => $unused) {
            $ordered[$name] = $this->diagram->nodes[$name];
        }
        $this->diagram->nodes = $ordered + $this->diagram->nodes;
        foreach ($this->diagram->pendingPipelines as $pipeline) {
            $parent = $this->diagram->nodes[$pipeline['parent']] ?? null;
            if (null === $parent) {
                throw $pipeline['statement']->error('Wardley pipeline parent must have coordinates');
            }
            $members = [];
            foreach ($pipeline['items'] as $item) {
                $id = $pipeline['parent'].'_'.$item['name'];
                $members[] = $id;
                $this->diagram->nodes[$id] = [
                    'label' => $item['name'],
                    'visibility' => $parent['visibility'],
                    'evolution' => $item['evolution'],
                    'origin' => $item['origin'],
                    'kind' => 'pipeline-component',
                    'dx' => $item['dx'],
                    'dy' => $item['dy'],
                    'strategy' => '',
                    'inertia' => false,
                ];
            }
            $this->diagram->pipelines[$pipeline['parent']] = $members;
        }

        return $this->diagram;
    }
}
