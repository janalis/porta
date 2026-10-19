<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * Pinned git graph styles as color tokens per branch lane. Classic themes cycle `git0`…`git7`;
 * the neo and redux themes generate their own: lane 0 in the node border, later lanes in `gitN`
 * (neo), the node border (redux) or the palette slot (redux color themes).
 */
final readonly class GitColors
{
    /** Pinned `THEME_COLOR_LIMIT`: branch indexes wrap before any color is chosen. */
    private const int LIMIT = 8;

    public function __construct(
        private string $theme = 'default',
    ) {}

    /** A commit bullet's fill and stroke, and the arrows into its lane. */
    public function commit(int $lane): string
    {
        return $this->lane($lane, classic: '@git%d');
    }

    /** @return array{string, string} the highlight square's fill and stroke */
    public function highlight(int $lane): array
    {
        $lane %= self::LIMIT;

        return match (true) {
            $this->color() && 0 === $lane => ['@mainBkg', '@nodeBorder'],
            default => [$this->lane($lane, classic: '@gitInv%d'), $this->lane($lane, classic: '@gitInv%d')],
        };
    }

    /** The glyphs inside merge, reverse and highlight commits. */
    public function inner(): string
    {
        return $this->generated() ? '@mainBkg' : '@primaryColor';
    }

    /**
     * A branch label's box fill, box stroke and text.
     *
     * @return array{string, string, string}
     */
    public function branch(int $lane): array
    {
        $lane %= self::LIMIT;
        if (!$this->generated()) {
            return ['@git'.$lane, 'none', '@gitBranchLabel'.$lane];
        }
        $stroke = $this->color() ? $this->commit($lane) : '@nodeBorder';
        // Pinned color themes fill later lanes with their slot, except on dark backgrounds.
        $fill = $this->color() && 0 !== $lane && !\str_contains($this->theme, 'dark') ? $stroke : '@mainBkg';
        $text = 'neo' === $this->family() && 0 !== $lane ? '@gitBranchLabel'.$lane : '@nodeBorder';

        return [$fill, $stroke, $text];
    }

    /** @return array{string, string} the commit label's background and text */
    public function caption(): array
    {
        return $this->generated() ? ['none', '@nodeBorder'] : ['@commitLabelBackground@50', '@commitLabelColor'];
    }

    /** @return array{float, float} pinned `.arrow` and `.branch` widths: 8px arrows but 2px under redux, 1px tracks but 2px on generated themes */
    public function widths(): array
    {
        return ['redux' === $this->family() ? 0.2 : 0.8, $this->generated() ? 0.2 : 0.1];
    }

    /** @return array{string, string, string} the tag flag's background, border and text */
    public function tag(): array
    {
        return $this->generated() ? ['@mainBkg', '@nodeBorder', '@tagLabelColor'] : ['@tagLabelBackground', '@tagLabelBorder', '@tagLabelColor'];
    }

    public function line(): string
    {
        return '@commitLineColor|lineColor';
    }

    private function lane(int $lane, string $classic): string
    {
        $lane %= self::LIMIT;

        return match (true) {
            !$this->generated(), 'neo' === $this->family() && 0 !== $lane => \sprintf($classic, $lane),
            $this->color() && 0 !== $lane => '@slot:stroke:'.$lane,
            default => '@nodeBorder',
        };
    }

    private function generated(): bool
    {
        return '' !== $this->family();
    }

    private function color(): bool
    {
        return \str_ends_with($this->theme, '-color');
    }

    private function family(): string
    {
        return match (true) {
            \str_starts_with($this->theme, 'neo') => 'neo',
            \str_starts_with($this->theme, 'redux') => 'redux',
            default => '',
        };
    }
}
