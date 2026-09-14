<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Config\EditorConfig\ResolvedEditorConfig;

/**
 * A file's effective indentation policy: tabs vs spaces, the indent size and
 * the hard-tab display width. Split out of {@see Document} so the buffer class
 * is not also the indentation authority — the policy is an independent axis
 * read by the renderer, the position mapper and the LSP formatter, and it is
 * overridden by per-language settings and `.editorconfig` without touching the
 * text.
 */
final class IndentModel
{
    public function __construct(
        public private(set) bool $usesTabs,
        public private(set) int $indentSize,
        public private(set) int $tabWidth,
    ) {}

    /**
     * Read a file's own indentation off its lines.
     *
     * Tabs vs spaces is the first line that starts with either. The SIZE is the
     * most common STEP between the indents of consecutive non-blank lines,
     * which is what an indent level is: `    public function` under `{` says
     * four, whatever any default says. Ties go to the smaller step, and a file
     * that indents nothing keeps $default — there is nothing to read.
     *
     * A constant default is what made the guides lie: it drew one per two
     * columns down a four-space file, so every block looked twice as deep as it
     * was, and Tab inserted half an indent.
     *
     * @param list<string> $lines
     */
    public static function detect(array $lines, int $default): self
    {
        $steps = [];
        $previous = null;
        $usesTabs = null;
        foreach ($lines as $line) {
            $usesTabs ??= match (true) {
                \str_starts_with($line, "\t") => true,
                \str_starts_with($line, ' ') => false,
                default => null,
            };
            if ('' === \trim($line)) {
                continue; // a blank line indents nothing and says nothing
            }
            $width = \strspn($line, characters: ' ');
            $step = null === $previous ? 0 : \abs($width - $previous);
            $previous = $width;
            if ($step > 0 && $step <= 8) {
                $steps[$step] = ($steps[$step] ?? 0) + 1;
            }
        }
        $size = self::commonest($steps) ?? $default;

        return new self($usesTabs ?? false, $size, $size);
    }

    /**
     * The most frequent step, smaller first on a tie.
     *
     * @param array<int, int> $steps step => how many lines took it
     */
    private static function commonest(array $steps): ?int
    {
        if ([] === $steps) {
            return null;
        }
        \ksort($steps);
        \arsort($steps, flags: \SORT_NUMERIC);

        return \array_key_first($steps);
    }

    /**
     * Force the effective indentation: the unit (tabs vs spaces) and its size —
     * one indent level is $size spaces, or one hard tab displayed $size columns
     * wide.
     */
    public function set(IndentKind $kind, int $size): void
    {
        $this->usesTabs = IndentKind::Tabs === $kind;
        $this->indentSize = \max(1, $size);
        $this->tabWidth = \max(1, $size);
    }

    /** The indentation unit for one level: a tab, or {@see $indentSize} spaces. */
    public function unit(): string
    {
        return $this->usesTabs ? "\t" : \str_repeat(' ', \max(0, $this->indentSize));
    }

    /** Display width of one indent level: the tab width for tabbed files, else the indent size. */
    public function displayWidth(): int
    {
        return $this->usesTabs ? $this->tabWidth : $this->indentSize;
    }

    /**
     * Override indentation from a resolved `.editorconfig`. Only properties the
     * config actually specified take effect, so a file without a matching
     * `.editorconfig` keeps its auto-detected tabs/spaces and default size.
     */
    public function applyEditorConfig(ResolvedEditorConfig $config): void
    {
        if (null !== $config->usesTabs) {
            $this->usesTabs = $config->usesTabs;
        }
        if (null !== $config->indentSize) {
            $this->indentSize = $config->indentSize;
        }
        if (null !== $config->tabWidth) {
            $this->tabWidth = $config->tabWidth;
        }
    }
}
