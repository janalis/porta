<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Document-local geometry; browser sizing and marker URLs do not affect intrinsic native graphics. */
final readonly class FlowchartConfiguration
{
    /** The options this family's native rendering reads here; MermaidConfiguration leaves them to this parser. */
    public const array KEYS = [
        'curve',
        'subGraphTitleMargin',
        'nodeSpacing',
        'rankSpacing',
        'diagramPadding',
        'titleTopMargin',
        'padding',
        'minNodeWidth',
        'useMaxWidth',
        'arrowMarkerAbsolute',
        'inheritDir',
    ];

    /** @param array<array-key, bool|float|string> $options */
    public function __construct(
        private array $options = [],
    ) {}

    public static function parse(mixed $object, MermaidStatement $statement): self
    {
        if (!$object instanceof \stdClass) {
            throw $statement->error('Flowchart configuration must be a mapping');
        }
        $options = [];
        // @mago-expect analysis:mixed-assignment -- Closed option names and scalar types are checked before storage.
        foreach (\get_object_vars($object) as $key => $value) {
            if (!\in_array($key, self::KEYS, strict: true)) {
                continue; // Other pinned options are validated by MermaidOptionSchema.
            }
            if ('curve' === $key) {
                // Pinned edges resolve only string names; any later non-string value still replaces an
                // earlier name and means the basis default.
                $options[$key] = \is_string($value) ? $value : '';
                continue;
            }
            if ('subGraphTitleMargin' === $key) {
                $options += self::margins($value, $statement);
                continue;
            }
            // @mago-expect analysis:mixed-assignment -- A value stays mixed until its key's rule narrows it below.
            $value = MermaidOptions::numeric($value);
            $valid = match ($key) {
                'nodeSpacing', 'rankSpacing', 'diagramPadding', 'titleTopMargin' => \is_int($value) && self::bounded($value),
                'padding', 'minNodeWidth' => self::bounded($value),
                default => \is_bool($value), // useMaxWidth, arrowMarkerAbsolute and inheritDir
            };
            if (!$valid || (!\is_bool($value) && !\is_int($value) && !\is_float($value))) {
                throw $statement->error('Unsupported flowchart configuration value ('.$key.')');
            }
            $options[$key] = \is_bool($value) ? $value : (float) $value;
        }

        return new self($options);
    }

    private static function bounded(mixed $value): bool
    {
        return (\is_int($value) || \is_float($value)) && \is_finite((float) $value) && $value >= 0 && $value <= 1000;
    }

    /** @return array<string, float> */
    private static function margins(mixed $value, MermaidStatement $statement): array
    {
        if (!$value instanceof \stdClass) {
            throw $statement->error('Flowchart subGraphTitleMargin must be a mapping');
        }
        $margins = [];
        // @mago-expect analysis:mixed-assignment -- Each margin is narrowed to a bounded integer below.
        foreach (\get_object_vars($value) as $edge => $margin) {
            if (!\is_int($margin) || !\in_array($edge, ['top', 'bottom'], strict: true) || !self::bounded($margin)) {
                throw $statement->error('Unsupported flowchart subgraph title margin ('.$edge.')');
            }
            $margins['subGraphTitleMargin.'.$edge] = (float) $margin;
        }

        return $margins;
    }

    public function overlay(self $later): self
    {
        return new self($later->options + $this->options);
    }

    public function configured(): bool
    {
        return [] !== $this->options;
    }

    public function curve(): ?string
    {
        $value = $this->options['curve'] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function inheritedDirection(): ?bool
    {
        $value = $this->options['inheritDir'] ?? null;

        return \is_bool($value) ? $value : null;
    }

    public function titleGap(): float
    {
        return $this->spacing('subGraphTitleMargin.top', default: 0.0) + $this->spacing('subGraphTitleMargin.bottom', default: 0.0);
    }

    public function spacing(string $key, float $default): float
    {
        $value = $this->options[$key] ?? null;

        return \is_float($value) ? $value / 10 : $default;
    }
}
