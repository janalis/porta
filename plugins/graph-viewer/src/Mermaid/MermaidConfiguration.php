<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Mermaid\Theme\JsObject;
use Porta\GraphViewer\Mermaid\Theme\MermaidAppearance;

/** Supported document options; null means absent so an explicit false survives merging. */
final readonly class MermaidConfiguration
{
    /** Section options this class checks with its own rules. */
    private const array OWN = [
        'sequence' => ['showSequenceNumbers', 'mirrorActors', 'hideUnusedParticipants', 'wrap', 'actorMargin'],
        'packet' => ['showBits', 'bitOrder', 'bitsPerRow', 'bitWidth', 'rowHeight', 'paddingY', 'paddingX'],
    ];

    /** @param list<\stdClass> $inits Raw init directive objects in source order. */
    public function __construct(
        public ?bool $sequenceNumbers = null,
        public ?string $pieHighlight = null,
        private ?MermaidStatement $origin = null,
        public ?bool $mirrorActors = null,
        public ?bool $hideUnusedParticipants = null,
        public ?bool $sequenceWrap = null,
        public ?bool $rootWrap = null,
        public ?bool $packetShowBits = null,
        public ?string $packetBitOrder = null,
        public ?int $packetBitsPerRow = null,
        public ?float $packetBitWidth = null,
        public ?float $packetRowHeight = null,
        public ?float $packetPaddingY = null,
        public ?float $packetPaddingX = null,
        public ?int $sequenceActorMargin = null,
        public ?GitConfiguration $gitGraph = null,
        public ?UsecaseConfiguration $usecase = null,
        public ?ArchitectureConfiguration $architecture = null,
        public ?PieConfiguration $pie = null,
        public ?FlowchartConfiguration $flowchart = null,
        public ?\stdClass $frontMatter = null,
        public array $inits = [],
    ) {}

    /**
     * One front matter `config` or init directive. Its typed options are validated here, while the
     * raw object is kept so the appearance can be assembled the way pinned `preprocessDiagram` does.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- Where the object came from is the one fact the pinned merge order needs.
    public static function parse(\stdClass $object, MermaidStatement $statement, bool $frontMatter = false): self
    {
        $schema = MermaidOptionSchema::bundled();
        $configuration = new self(origin: $statement, frontMatter: $frontMatter ? $object : null, inits: $frontMatter ? [] : [$object]);
        // @mago-expect analysis:mixed-assignment -- Each decoded section is checked against the schema below.
        foreach (\get_object_vars($object) as $family => $section) {
            // @mago-expect analysis:mixed-assignment -- A section's family options, narrowed per family below.
            [$skip, $section] = self::checked((string) $family, $section, $statement, $schema);
            if ($skip) {
                continue;
            }
            $configuration = $configuration->merged(self::family((string) $family, $section, $statement));
        }

        return $configuration;
    }

    /**
     * The typed options one section sets; other pinned options are read from MermaidOptions.
     * The schema check has made every family section a mapping.
     */
    private static function family(string $family, mixed $section, MermaidStatement $statement): self
    {
        $options = JsObject::object($section);

        return match ($family) {
            'logLevel' => self::logLevel($section, $statement),
            'wrap' => new self(rootWrap: self::boolean($section, $statement, key: 'wrap', scope: 'Mermaid')),
            'pie' => new self(
                pieHighlight: \property_exists($options, 'highlightSlice') ? self::highlight($options->highlightSlice, $statement) : null,
                pie: PieConfiguration::parse($options, $statement),
            ),
            'architecture' => new self(architecture: ArchitectureConfiguration::parse($options, $statement)),
            'usecase' => new self(usecase: UsecaseConfiguration::parse($options, $statement)),
            'flowchart' => new self(flowchart: FlowchartConfiguration::parse($section, $statement)),
            'gitGraph' => new self(gitGraph: GitConfiguration::parse($options, $statement)),
            'sequence' => self::sequence($options, $statement),
            'packet' => self::packet($options, $statement),
            default => new self(),
        };
    }

    private static function sequence(\stdClass $section, MermaidStatement $statement): self
    {
        $configuration = new self();
        // @mago-expect analysis:mixed-assignment -- Each option is narrowed to its supported scalar type below.
        foreach (\get_object_vars($section) as $key => $value) {
            $configuration = $configuration->merged(match ($key) {
                'showSequenceNumbers' => new self(sequenceNumbers: self::boolean($value, $statement, key: 'showSequenceNumbers')),
                'mirrorActors' => new self(mirrorActors: self::boolean($value, $statement, key: 'mirrorActors')),
                'hideUnusedParticipants' => new self(hideUnusedParticipants: self::boolean($value, $statement, key: 'hideUnusedParticipants')),
                'wrap' => new self(sequenceWrap: self::boolean($value, $statement, key: 'wrap')),
                'actorMargin' => new self(sequenceActorMargin: self::margin($value, $statement)),
                default => new self(),
            });
        }

        return $configuration;
    }

    private static function packet(\stdClass $section, MermaidStatement $statement): self
    {
        $configuration = new self();
        // @mago-expect analysis:mixed-assignment -- Each option is narrowed to its supported scalar type below.
        foreach (\get_object_vars($section) as $key => $value) {
            $configuration = $configuration->merged(match ($key) {
                'showBits' => new self(packetShowBits: self::boolean($value, $statement, key: 'showBits', scope: 'Packet')),
                'bitOrder' => new self(packetBitOrder: self::order($value, $statement)),
                'bitsPerRow' => new self(packetBitsPerRow: self::bits($value, $statement)),
                'bitWidth' => new self(packetBitWidth: self::dimension($value, $statement, key: 'bitWidth')),
                'rowHeight' => new self(packetRowHeight: self::dimension($value, $statement, key: 'rowHeight')),
                'paddingY' => new self(packetPaddingY: self::dimension($value, $statement, key: 'paddingY', minimum: 0)),
                'paddingX' => new self(packetPaddingX: self::dimension($value, $statement, key: 'paddingX', minimum: 0)),
                default => new self(),
            });
        }

        return $configuration;
    }

    private static function margin(mixed $value, MermaidStatement $statement): int
    {
        if (!\is_int($value) || $value < 0 || $value > 1000) {
            throw $statement->error('Sequence actorMargin must be an integer from 0 to 1000');
        }

        return $value;
    }

    private static function order(mixed $value, MermaidStatement $statement): string
    {
        if (!\is_string($value) || !\in_array($value, ['ascending', 'descending'], strict: true)) {
            throw $statement->error('Packet bitOrder must be ascending or descending');
        }

        return $value;
    }

    private static function bits(mixed $value, MermaidStatement $statement): int
    {
        if (!\is_int($value) || $value < 1 || $value > 1024) {
            throw $statement->error('Packet bitsPerRow must be an integer from 1 to 1024');
        }

        return $value;
    }

    public function located(string $message): MermaidSyntaxError
    {
        return ($this->origin ?? new MermaidStatement('', offset: 0, line: 0, column: 0))->error($message);
    }

    /** Native rendering has no browser console; the documented levels never change its scene. */
    private static function logLevel(mixed $value, MermaidStatement $statement): self
    {
        if (!\in_array($value, [0, 1, 2, 3, 4, 5, 'trace', 'debug', 'info', 'warn', 'error', 'fatal'], strict: true)) {
            throw $statement->error('Mermaid logLevel must be a level from 0 to 5 or trace, debug, info, warn, error, fatal');
        }

        return new self();
    }

    private static function dimension(mixed $value, MermaidStatement $statement, string $key, int $minimum = 1): float
    {
        if ((!\is_int($value) && !\is_float($value)) || $value < $minimum || $value > 1000 || !\is_finite((float) $value)) {
            throw $statement->error('Packet '.$key.' must be a finite number from '.$minimum.' to 1000');
        }

        return (float) $value;
    }

    private static function boolean(mixed $value, MermaidStatement $statement, string $key, string $scope = 'Sequence'): bool
    {
        if (!\is_bool($value)) {
            throw $statement->error($scope.' '.$key.' must be boolean');
        }

        return $value;
    }

    private static function highlight(mixed $value, MermaidStatement $statement): string
    {
        if (!\is_string($value)) {
            throw $statement->error('Pie highlightSlice must be a string');
        }
        if ('hover' === $value) {
            throw $statement->error('Pie hover highlighting requires the Mermaid CLI');
        }

        return MermaidText::decode($value);
    }

    /** A root setting initializes the sequence database, ahead of its family default. */
    public function wrapping(): ?bool
    {
        return $this->rootWrap ?? $this->sequenceWrap;
    }

    public function overlay(self $later): self
    {
        if ($later->unconfigured()) {
            return $this;
        }

        return $this->merged($later);
    }

    private function merged(self $later): self
    {
        return new self(
            $later->sequenceNumbers ?? $this->sequenceNumbers,
            $later->pieHighlight ?? $this->pieHighlight,
            // Diagnostics stay located at the document that set a typed option.
            $later->untyped() ? $this->origin : $later->origin ?? $this->origin,
            $later->mirrorActors ?? $this->mirrorActors,
            $later->hideUnusedParticipants ?? $this->hideUnusedParticipants,
            $later->sequenceWrap ?? $this->sequenceWrap,
            $later->rootWrap ?? $this->rootWrap,
            $later->packetShowBits ?? $this->packetShowBits,
            $later->packetBitOrder ?? $this->packetBitOrder,
            $later->packetBitsPerRow ?? $this->packetBitsPerRow,
            $later->packetBitWidth ?? $this->packetBitWidth,
            $later->packetRowHeight ?? $this->packetRowHeight,
            $later->packetPaddingY ?? $this->packetPaddingY,
            $later->packetPaddingX ?? $this->packetPaddingX,
            $later->sequenceActorMargin ?? $this->sequenceActorMargin,
            $this->gitGraph?->overlay($later->gitGraph ?? new GitConfiguration()) ?? $later->gitGraph,
            $this->usecase?->overlay($later->usecase ?? new UsecaseConfiguration()) ?? $later->usecase,
            $this->architecture?->overlay($later->architecture ?? new ArchitectureConfiguration()) ?? $later->architecture,
            $this->pie?->overlay($later->pie ?? new PieConfiguration()) ?? $later->pie,
            $this->flowchart?->overlay($later->flowchart ?? new FlowchartConfiguration()) ?? $later->flowchart,
            $later->frontMatter ?? $this->frontMatter,
            [...$this->inits, ...$later->inits],
        );
    }

    /**
     * A section checked against the pinned schema and without its appearance keys, and whether
     * it is an appearance key itself, which no family reads.
     *
     * @return array{bool, mixed}
     */
    private static function checked(string $family, mixed $section, MermaidStatement $statement, MermaidOptionSchema $schema): array
    {
        if (\in_array($family, MermaidAppearance::KEYS, strict: true)) {
            return [true, $section];
        }
        if (!\in_array($family, ['logLevel', 'wrap'], strict: true)) {
            $schema->validate($family, $section, $statement, [...MermaidAppearance::SCOPED, ...self::own($family)]);
        }

        return [false, MermaidAppearance::options($section)];
    }

    /** @return list<string> */
    private static function own(string $family): array
    {
        return match ($family) {
            'pie' => PieConfiguration::KEYS,
            'architecture' => ArchitectureConfiguration::KEYS,
            'usecase' => UsecaseConfiguration::KEYS,
            'flowchart' => FlowchartConfiguration::KEYS,
            'gitGraph' => GitConfiguration::KEYS,
            default => self::OWN[$family] ?? [],
        };
    }

    private function unconfigured(): bool
    {
        return $this->untyped() && null === $this->frontMatter && [] === $this->inits;
    }

    private function untyped(): bool
    {
        return (
            !($this->flowchart?->configured() ?? false)
            && !($this->pie?->configured() ?? false)
            && !($this->architecture?->configured() ?? false)
            && !($this->usecase?->configured() ?? false)
            && !($this->gitGraph?->configured() ?? false)
            && null === $this->sequenceNumbers
            && null === $this->pieHighlight
            && null === $this->mirrorActors
            && null === $this->hideUnusedParticipants
            && null === $this->sequenceWrap
            && null === $this->rootWrap
            && null === $this->packetShowBits
            && null === $this->packetBitOrder
            && null === $this->packetBitsPerRow
            && null === $this->packetBitWidth
            && null === $this->packetRowHeight
            && null === $this->packetPaddingY
            && null === $this->sequenceActorMargin
            && null === $this->packetPaddingX
        );
    }

    private function sequenceConfigured(): bool
    {
        return (
            null !== $this->sequenceActorMargin
            || null !== $this->sequenceNumbers
            || null !== $this->mirrorActors
            || null !== $this->hideUnusedParticipants
            || null !== $this->sequenceWrap
            || null !== $this->rootWrap
        );
    }

    private function packetConfigured(): bool
    {
        return (
            null !== $this->packetShowBits
            || null !== $this->packetBitOrder
            || null !== $this->packetBitsPerRow
            || null !== $this->packetBitWidth
            || null !== $this->packetRowHeight
            || null !== $this->packetPaddingY
            || null !== $this->packetPaddingX
        );
    }

    public function validateFamily(string $family): void
    {
        $scopes = [
            'flowchart' => $this->flowchart?->configured() ?? false,
            'pie' => null !== $this->pieHighlight || ($this->pie?->configured() ?? false),
            'architecture' => $this->architecture?->configured() ?? false,
            'usecase' => $this->usecase?->configured() ?? false,
            'git' => $this->gitGraph?->configured() ?? false,
            'sequence' => $this->sequenceConfigured(),
            'packet' => $this->packetConfigured(),
        ];
        foreach ($scopes as $scope => $configured) {
            if ($configured && $scope !== $family) {
                throw $this->located('Mermaid configuration does not apply to this diagram family');
            }
        }
    }
}
