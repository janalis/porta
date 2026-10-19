<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

/** Bundled monospaced faces share advances, preserving geometry when CSS changes typography. */
enum GraphFont: string
{
    case Regular = 'Regular';
    case Bold = 'Bold';
    case Italic = 'Italic';
    case BoldItalic = 'BoldItalic';

    public function weight(): string
    {
        return \in_array($this, [self::Bold, self::BoldItalic], strict: true) ? 'bold' : 'normal';
    }

    public function style(): string
    {
        return \in_array($this, [self::Italic, self::BoldItalic], strict: true) ? 'italic' : 'normal';
    }

    /** @param array<string, float|string|bool> $style */
    public function overlay(array $style): self
    {
        $bold = $style['font-weight'] ?? 'bold' === $this->weight();
        $italic = $style['font-style'] ?? 'italic' === $this->style();

        return match (true) {
            true === $bold && true === $italic => self::BoldItalic,
            true === $bold => self::Bold,
            true === $italic => self::Italic,
            default => self::Regular,
        };
    }
}
