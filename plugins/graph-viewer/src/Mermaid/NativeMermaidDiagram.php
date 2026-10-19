<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** A native request binds its detected family to one parser, lexer and layout. */
final readonly class NativeMermaidDiagram
{
    /** Pinned detector ids where they differ from the native family names. */
    private const array TYPES = ['flowchart' => 'flowchart-v2', 'class' => 'classDiagram', 'state' => 'stateDiagram', 'quadrant' => 'quadrantChart', 'xy' => 'xychart', 'git' => 'gitGraph'];
    /** Pinned `getDiagramConfigKey` aliases from detector id to configuration section. */
    private const array SECTIONS = ['flowchart-v2' => 'flowchart', 'classDiagram' => 'class', 'stateDiagram' => 'state', 'xychart' => 'xyChart'];

    private string $header;
    public bool $supportsTitle;
    public MermaidPalette $palette;
    public MermaidOptions $options;
    private RailroadParser|VennParser|EventModelingParser|ArchitectureParser|UsecaseParser|GanttParser|AgentflowParser|SwimlaneParser|BlockParser|C4Parser|WardleyParser|GitParser|StateParser|ClassParser|ErParser|InfoParser|RequirementParser|CynefinParser|RadarParser|IshikawaParser|TreeViewParser|TreemapParser|SankeyParser|KanbanParser|PacketParser|XyParser|QuadrantParser|MindmapParser|TimelineParser|JourneyParser|SequenceParser|PieParser|FlowchartParser $parser;
    private RailroadLexer|VennLexer|EventModelingLexer|ArchitectureLexer|UsecaseLexer|GanttLexer|AgentflowLexer|BlockLexer|C4Lexer|WardleyLexer|GitLexer|StateLexer|ClassLexer|ErLexer|InfoLexer|RequirementLexer|CynefinLexer|RadarLexer|IshikawaLexer|TreeViewLexer|MermaidLexer|SankeyLexer|KanbanLexer|XyLexer|QuadrantLexer|MindmapLexer|TimelineLexer|JourneyLexer $lexer;

    public function __construct(
        string $source,
        private MermaidConfiguration $configuration,
        MermaidStyleSource $styles = new MermaidStyleSource(),
    ) {
        $this->header = match (true) {
            EventModelingLexer::detect($source) => 'eventmodeling',
            null !== RailroadLexer::header($source) => RailroadLexer::header($source) ?? '',
            default => new MermaidLexer($source, textual: true)->statements()->current()->text ?? '',
        };
        $kind = self::kind($this->header);
        $this->supportsTitle = 'info' !== $kind;
        $configuration->validateFamily(match ($kind) {
            'architecture', 'usecase', 'git', 'sequence', 'pie', 'packet', 'flowchart' => $kind,
            // Pinned swimlanes draw with the flowchart renderer and read its options.
            'swimlane' => 'flowchart',
            default => 'other',
        });
        $type = self::TYPES[$kind] ?? $kind;
        try {
            $appearance = $styles->appearance($configuration, $type, self::SECTIONS[$type] ?? $type);
            $this->options = $styles->options($configuration, $type, self::SECTIONS[$type] ?? $type);
        } catch (\InvalidArgumentException $error) {
            throw $configuration->located('Mermaid theme configuration cannot be drawn: '.$error->getMessage());
        }
        $this->palette = new MermaidPalette($appearance, MermaidRoles::forKind($kind));
        $this->parser = match ($kind) {
            'railroad' => new RailroadParser(),
            'venn' => new VennParser(),
            'eventmodeling' => new EventModelingParser(),
            'architecture' => new ArchitectureParser(),
            'usecase' => new UsecaseParser(),
            'gantt' => new GanttParser(),
            'agentflow' => new AgentflowParser(),
            'swimlane' => new SwimlaneParser(),
            'block' => new BlockParser(),
            'c4' => new C4Parser(),
            'wardley' => new WardleyParser(),
            'git' => new GitParser($configuration->gitGraph ?? new GitConfiguration()),
            'state' => new StateParser(),
            'class' => new ClassParser(),
            'er' => new ErParser(),
            'info' => new InfoParser(),
            'requirement' => new RequirementParser(),
            'cynefin' => new CynefinParser(),
            'radar' => new RadarParser(),
            'ishikawa' => new IshikawaParser(),
            'treeView' => new TreeViewParser(),
            'treemap' => new TreemapParser(),
            'sankey' => new SankeyParser(),
            'kanban' => new KanbanParser(),
            'packet' => new PacketParser($configuration),
            'xy' => new XyParser(),
            'quadrant' => new QuadrantParser(),
            'mindmap' => new MindmapParser(),
            'timeline' => new TimelineParser(),
            'journey' => new JourneyParser(),
            'sequence' => new SequenceParser($configuration),
            'pie' => new PieParser(),
            default => new FlowchartParser($configuration->flowchart),
        };
        $this->lexer = match ($kind) {
            'railroad' => new RailroadLexer($source),
            'venn' => new VennLexer($source),
            'eventmodeling' => new EventModelingLexer($source),
            'architecture' => new ArchitectureLexer($source),
            'usecase' => new UsecaseLexer($source),
            'gantt' => new GanttLexer($source),
            'agentflow' => new AgentflowLexer($source),
            'block' => new BlockLexer($source),
            'c4' => new C4Lexer($source),
            'wardley' => new WardleyLexer($source),
            'git' => new GitLexer($source),
            'state' => new StateLexer($source),
            'class' => new ClassLexer($source),
            'er' => new ErLexer($source),
            'info' => new InfoLexer($source),
            'requirement' => new RequirementLexer($source),
            'cynefin' => new CynefinLexer($source),
            'radar' => new RadarLexer($source),
            'ishikawa' => new IshikawaLexer($source),
            'treeView' => new TreeViewLexer($source),
            'treemap' => new MermaidLexer($source, chart: true),
            'sankey' => new SankeyLexer($source),
            'kanban' => new KanbanLexer($source),
            'packet' => new MermaidLexer($source, chart: true),
            'xy' => new XyLexer($source),
            'quadrant' => new QuadrantLexer($source),
            'mindmap' => new MindmapLexer($source),
            'timeline' => new TimelineLexer($source),
            'journey' => new JourneyLexer($source),
            default => new MermaidLexer($source, textual: 'sequence' === $kind, chart: 'pie' === $kind),
        };
    }

    private static function kind(string $header): string
    {
        return match (true) {
            'sequencediagram' === \strtolower($header) => 'sequence',
            1 === \preg_match('/^pie\b/i', $header) => 'pie',
            1 === \preg_match('/^journey\b/i', $header) => 'journey',
            'mindmap' === \strtolower($header) => 'mindmap',
            1 === \preg_match('/^timeline\b/i', $header) => 'timeline',
            'quadrantchart' === \strtolower($header) => 'quadrant',
            1 === \preg_match('/^xychart(?:-beta)?(?:\s|$)/i', $header) => 'xy',
            1 === \preg_match('/^packet(?:-beta)?$/i', $header) => 'packet',
            'kanban' === \strtolower($header) => 'kanban',
            1 === \preg_match('/^sankey(?:-beta)?$/i', $header) => 'sankey',
            1 === \preg_match('/^treemap(?:-beta)?$/i', $header) => 'treemap',
            'treeview-beta' === \strtolower($header) => 'treeView',
            1 === \preg_match('/^ishikawa(?:-beta)?$/i', $header) => 'ishikawa',
            1 === \preg_match('/^radar-beta\s*:?(?:\s|$)/i', $header) => 'radar',
            1 === \preg_match('/^cynefin-beta:?(?:\s|$)/i', $header) => 'cynefin',
            'requirementdiagram' === \strtolower($header) => 'requirement',
            1 === \preg_match('/^info(?:\s|$)/', $header) => 'info',
            'erdiagram' === \strtolower($header) => 'er',
            \in_array($header, ['classDiagram', 'classDiagram-v2'], strict: true) => 'class',
            1 === \preg_match('/^railroad(?:-(?:ebnf|abnf|peg))?-beta(?:\s|$)/', $header) => 'railroad',
            1 === \preg_match('/^venn-beta\b/i', $header) => 'venn',
            1 === \preg_match('/^eventmodeling(?:\s|$)/', $header) => 'eventmodeling',
            1 === \preg_match('/^architecture-beta(?:\s|$)/', $header) => 'architecture',
            1 === \preg_match('/^usecase-beta(?:\s|$)/', $header) => 'usecase',
            'gantt' === \strtolower($header) => 'gantt',
            1 === \preg_match('/^agentflow-beta(?:\s|$)/', $header) => 'agentflow',
            1 === \preg_match('/^swimlane-beta(?:\s|$)/', $header) => 'swimlane',
            1 === \preg_match('/^block(?:-beta)?(?:\s|$)/', $header) => 'block',
            1 === \preg_match('/^C4(?:Context|Container|Component|Dynamic|Deployment)$/', $header) => 'c4',
            'wardley-beta' === $header => 'wardley',
            1 === \preg_match('/^gitGraph(?:\s|:|$)/', $header) => 'git',
            1 === \preg_match('/^stateDiagram(?:-v2)?$/i', $header) => 'state',
            default => 'flowchart',
        };
    }

    /** @return \Generator<int, MermaidStatement> */
    public function statements(): \Generator
    {
        yield from $this->lexer->statements();
    }

    public function accept(MermaidStatement $statement): void
    {
        $this->parser->accept($statement);
    }

    public function isTitle(MermaidStatement $statement): bool
    {
        if ($this->parser instanceof EventModelingParser) {
            return 1 === \preg_match('/^[\t ]+title(?:[\t ]|$)/', $statement->text);
        }
        $pattern = match (true) {
            $this->parser instanceof FlowchartParser || $this->parser instanceof KanbanParser => null,
            \str_starts_with($this->header, 'C4') => '/^(?:title\b|accTitle\s*:)/i',
            default => '/^(?:title\b|pie(?:[\t ]+showData)?[\t ]+title\b)/i',
        };

        return null !== $pattern && 1 === \preg_match($pattern, $statement->text);
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    public function layout(): \Generator
    {
        $source = $this->parser->finish();
        $layout = match (true) {
            $source instanceof RailroadDiagram => new RailroadLayout($this->options)->layout($source),
            $source instanceof VennDiagram => new VennLayout($this->palette)->layout($source),
            $source instanceof EventModelingDiagram => new EventModelingLayout()->layout($source),
            $source instanceof ArchitectureDiagram => new ArchitectureLayout($this->configuration->architecture ?? new ArchitectureConfiguration())->layout($source),
            $source instanceof UsecaseDiagram => new UsecaseLayout($this->configuration->usecase ?? new UsecaseConfiguration(), $this->options)->layout($source),
            $source instanceof MermaidGraph => $this->graphLayout($source),
            $source instanceof AgentflowDiagram => new AgentflowLayout()->layout($source),
            $source instanceof BlockDiagram => new BlockLayout()->layout($source),
            $source instanceof C4Diagram => new C4Layout($this->options)->layout($source),
            $source instanceof WardleyDiagram => new WardleyLayout()->layout($source),
            $source instanceof GitDiagram => new GitLayout($this->configuration->gitGraph ?? new GitConfiguration(), new GitColors($this->palette->appearance->theme))->layout($source),
            $source instanceof StateDiagram => new StateLayout()->layout($this->seeded($source->graph->styles, 'state', 24.0, $source)),
            $source instanceof ClassDiagram => new ClassLayout($this->options)->layout($source),
            $source instanceof ErDiagram => new ErLayout()->layout($this->seeded($source->styles, 'er', 12.0, $source)),
            $source instanceof RequirementDiagram => new RequirementLayout()->layout($this->seeded($source->styles, 'requirement', 14.0, $source)),
            default => $this->chartLayout($source),
        };
        yield from $layout;

        return $layout->getReturn();
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    private function chartLayout(GanttDiagram|CynefinDiagram|RadarDiagram|IshikawaDiagram|TreeViewDiagram|TreemapDiagram|SankeyDiagram|KanbanDiagram|PacketDiagram|XyDiagram|QuadrantDiagram|TimelineDiagram|JourneyDiagram|SequenceDiagram|PieDiagram $source): \Generator
    {
        return match (true) {
            $source instanceof GanttDiagram => new GanttLayout($source->calendar, $this->options)->layout($source),
            $source instanceof CynefinDiagram => new CynefinLayout($this->options)->layout($source),
            $source instanceof RadarDiagram => new RadarLayout($this->options, $this->palette)->layout($source),
            $source instanceof IshikawaDiagram => new IshikawaLayout()->layout($source),
            $source instanceof TreeViewDiagram => new TreeViewLayout($this->options)->layout($source),
            $source instanceof TreemapDiagram => new TreemapLayout($this->options)->layout($source),
            $source instanceof SankeyDiagram => new SankeyLayout($this->options)->layout($source),
            $source instanceof KanbanDiagram => new KanbanLayout($this->palette, $this->options)->layout($source),
            $source instanceof PacketDiagram => new PacketLayout($this->configuration)->layout($source),
            $source instanceof XyDiagram => new XyLayout($this->options)->layout($source),
            $source instanceof QuadrantDiagram => new QuadrantLayout($this->options)->layout($source),
            $source instanceof TimelineDiagram => new TimelineLayout($this->palette, $this->options)->layout($source),
            $source instanceof JourneyDiagram => new JourneyLayout($this->options)->layout($source),
            $source instanceof SequenceDiagram => new SequenceLayout($this->configuration, $this->options)->layout($source),
            $source instanceof PieDiagram => new PieLayout($this->configuration, $this->palette)->layout($source),
        };
    }

    /**
     * The diagram, its styles seeded with the family's configured `fontSize`.
     *
     * @template T of object
     * @param T $source
     * @return T
     */
    private function seeded(MermaidNodeStyles $styles, string $family, float $default, object $source): object
    {
        $styles->seed($this->options, $family, $default);

        return $source;
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    private function graphLayout(MermaidGraph $source): \Generator
    {
        MermaidMarkdownLabels::prepare($source, $this->options, $this->parser instanceof MindmapParser ? 'mindmap' : 'flowchart');
        $work = match (true) {
            $this->parser instanceof SwimlaneParser => new SwimlaneLayout()->layout($source),
            $this->parser instanceof FlowchartParser => new FlowchartLayout()->layout($source),
            $this->parser instanceof MindmapParser => $this->mindmap($source),
            default => new RankedGraphLayout()->layout($source),
        };
        yield from $work;

        return MermaidMarkdownLabels::apply($work->getReturn(), $source);
    }

    /** @return \Generator<int, null, mixed, MermaidScene> */
    private function mindmap(MermaidGraph $source): \Generator
    {
        $work = new RankedGraphLayout()->layout($source);
        yield from $work;
        $arranged = MindmapLayout::arrange($source, $work->getReturn());
        yield from $arranged;

        return new MindmapPaint($this->palette)->paint($source, $arranged->getReturn());
    }
}
