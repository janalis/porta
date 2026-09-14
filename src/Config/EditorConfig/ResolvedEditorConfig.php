<?php

declare(strict_types=1);

namespace Porta\Config\EditorConfig;

/**
 * The effective EditorConfig properties for a single file, after ancestor
 * discovery and section merging. Every field is nullable: a null means the
 * property was never specified, so the editor keeps its own default (e.g. the
 * per-file tabs/spaces auto-detection) rather than being overridden.
 *
 * Only the core, honorable properties are modelled — indentation is applied to
 * the live {@see \Porta\Editor\Document}; end-of-line / trim / final-newline are
 * applied on save by {@see DocumentWriter}; charset is surfaced for a warning
 * only (the editor is UTF-8 only).
 */
final readonly class ResolvedEditorConfig
{
    private function __construct(
        public ?bool $usesTabs,
        public ?int $indentSize,
        public ?int $tabWidth,
        public ?string $eol,
        public ?bool $trimTrailingWhitespace,
        public ?bool $insertFinalNewline,
        public ?string $charset,
    ) {}

    /** An all-null config: overrides nothing (used when no .editorconfig applies). */
    public static function none(): self
    {
        return new self(null, null, null, null, null, null, null);
    }

    /**
     * Build from a merged raw property map (keys already lowercased). Encodes the
     * spec's indent_size/tab_width coupling and enum coercion; unrecognised
     * values collapse to null so they never override the editor's defaults.
     *
     * @param array<string, string> $props
     */
    public static function fromProps(array $props): self
    {
        $rawIndentSize = ($props['indent_size'] ?? null) !== null ? \strtolower(\trim($props['indent_size'])) : null;
        $rawTabWidth = $props['tab_width'] ?? null;

        $tabWidth = self::toPositiveInt($rawTabWidth);
        $indentSize = null;
        if ('tab' === $rawIndentSize) {
            $indentSize = $tabWidth; // "indent_size = tab" follows tab_width
        }
        if ('tab' !== $rawIndentSize && null !== $rawIndentSize) {
            $indentSize = self::toPositiveInt($rawIndentSize);
            if (null !== $indentSize && null === $tabWidth) {
                $tabWidth = $indentSize; // tab_width defaults to a numeric indent_size
            }
        }

        return new self(
            self::indentStyle($props['indent_style'] ?? null),
            $indentSize,
            $tabWidth,
            self::eol($props['end_of_line'] ?? null),
            self::boolProp($props['trim_trailing_whitespace'] ?? null),
            self::boolProp($props['insert_final_newline'] ?? null),
            self::charset($props['charset'] ?? null),
        );
    }

    private static function indentStyle(?string $value): ?bool
    {
        return match (null === $value ? null : \strtolower(\trim($value))) {
            'tab' => true,
            'space' => false,
            default => null,
        };
    }

    private static function eol(?string $value): ?string
    {
        $eol = null === $value ? null : \strtolower(\trim($value));

        return \in_array($eol, ['lf', 'crlf', 'cr'], strict: true) ? $eol : null;
    }

    private static function charset(?string $value): ?string
    {
        $charset = null === $value ? null : \strtolower(\trim($value));

        return '' === $charset || null === $charset ? null : $charset;
    }

    private static function boolProp(?string $value): ?bool
    {
        return match (null === $value ? null : \strtolower(\trim($value))) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    private static function toPositiveInt(?string $value): ?int
    {
        if (null === $value || 1 !== \preg_match('/^\d+$/', \trim($value))) {
            return null;
        }

        return \max(1, (int) \trim($value));
    }
}
