<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Document-local typography and terminal-scaled spacing; viewport width stays caller-owned. */
final readonly class UsecaseConfiguration
{
    /** The options this family's native rendering reads here; MermaidConfiguration leaves them to this parser. */
    public const array KEYS = [
        'actorFontSize',
        'usecaseFontSize',
        'nodeSpacing',
        'rankSpacing',
        'diagramPadding',
        'actorFontFamily',
        'usecaseFontFamily',
        'actorFontWeight',
        'usecaseFontWeight',
        'useMaxWidth',
    ];

    /** @param array<array-key, bool|float|string> $options */
    public function __construct(
        private array $options = [],
    ) {}

    public static function parse(\stdClass $object, MermaidStatement $statement): self
    {
        $options = [];
        // @mago-expect analysis:mixed-assignment -- Each closed option is narrowed before storage.
        foreach (\get_object_vars($object) as $key => $value) {
            if (!\in_array($key, self::KEYS, strict: true)) {
                continue; // Other pinned options are validated by MermaidOptionSchema.
            }
            $valid = match ($key) {
                'actorFontSize', 'usecaseFontSize', 'nodeSpacing', 'rankSpacing', 'diagramPadding' => (\is_int($value) || \is_float($value))
                    && \is_finite((float) $value)
                    && $value >= 0
                    && $value <= 1000,
                // A family is a CSS font list, like the pinned default; drawing uses the bundled font.
                'actorFontFamily', 'usecaseFontFamily' => \is_string($value) && '' !== $value && \strlen($value) <= 256 && !\str_contains($value, ';'),
                'actorFontWeight', 'usecaseFontWeight' => \is_string($value) && '' !== $value && \strlen($value) <= 256 && !\str_contains($value, ';') && !\str_contains($value, ','),
                default => \is_bool($value), // useMaxWidth
            };
            if (!$valid || (!\is_bool($value) && !\is_string($value) && !\is_int($value) && !\is_float($value))) {
                throw $statement->error('Unsupported use-case configuration value ('.$key.')');
            }
            $options[$key] = \is_int($value) ? (float) $value : $value;
        }

        return new self($options);
    }

    public function overlay(self $later): self
    {
        return new self($later->options + $this->options);
    }

    public function configured(): bool
    {
        return [] !== $this->options;
    }

    public function spacing(string $key, float $default): float
    {
        $value = $this->options[$key] ?? null;

        return \is_float($value) ? $value / 10 : $default;
    }

    public function css(string $kind): string
    {
        $prefix = 'actor' === $kind ? 'actor' : 'usecase';
        $css = [];
        foreach (['Size' => 'font-size', 'Weight' => 'font-weight'] as $suffix => $property) {
            $value = $this->options[$prefix.'Font'.$suffix] ?? null;
            if (\is_string($value) || \is_float($value)) {
                $css[] = $property.':'.$value;
            }
        }

        return \implode(',', $css);
    }
}
