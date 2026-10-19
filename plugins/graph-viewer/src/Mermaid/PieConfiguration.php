<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Nullable geometry options retain authored zero values through directive overlays. */
final readonly class PieConfiguration
{
    /** The options this family's native rendering reads here; MermaidConfiguration leaves them to this parser. */
    public const array KEYS = ['highlightSlice', 'legendPosition', 'textPosition', 'donutHole'];

    public function __construct(
        public ?float $textPosition = null,
        public ?float $donutHole = null,
        public ?string $legendPosition = null,
    ) {}

    public static function parse(\stdClass $object, MermaidStatement $statement): self
    {
        $text = null;
        $hole = null;
        $legend = null;
        // @mago-expect analysis:mixed-assignment -- Closed geometry options are narrowed before assignment.
        foreach (\get_object_vars($object) as $key => $value) {
            if (!\in_array($key, self::KEYS, strict: true)) {
                continue; // Other pinned options are validated by MermaidOptionSchema.
            }
            if ('highlightSlice' === $key) {
                continue;
            }
            if ('legendPosition' === $key && \is_string($value) && \in_array($value, ['top', 'bottom', 'left', 'right', 'center'], strict: true)) {
                $legend = $value;
                continue;
            }
            if ((\is_int($value) || \is_float($value)) && \in_array($key, ['textPosition', 'donutHole'], strict: true) && \is_finite((float) $value)) {
                if ('textPosition' === $key && $value >= 0 && $value <= 1) {
                    $text = (float) $value;
                    continue;
                }
                if ('donutHole' === $key) {
                    $hole = $value > 0 && $value <= 0.9 ? (float) $value : 0.0;
                    continue;
                }
            }
            throw $statement->error('Unsupported pie configuration value ('.$key.')');
        }

        return new self($text, $hole, $legend);
    }

    public function overlay(self $later): self
    {
        return new self($later->textPosition ?? $this->textPosition, $later->donutHole ?? $this->donutHole, $later->legendPosition ?? $this->legendPosition);
    }

    public function configured(): bool
    {
        return null !== $this->textPosition || null !== $this->donutHole || null !== $this->legendPosition;
    }
}
