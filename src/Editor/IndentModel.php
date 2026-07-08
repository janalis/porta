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
        private bool $usesTabs,
        private int $indentSize,
        private int $tabWidth,
    ) {}

    public function usesTabs(): bool
    {
        return $this->usesTabs;
    }

    /**
     * Force the effective indentation: the unit (tabs vs spaces) and its size —
     * one indent level is $size spaces, or one hard tab displayed $size columns
     * wide.
     */
    public function set(bool $usesTabs, int $size): void
    {
        $this->usesTabs = $usesTabs;
        $this->indentSize = max(1, $size);
        $this->tabWidth = max(1, $size);
    }

    /** The indentation unit for one level: a tab, or {@see $indentSize} spaces. */
    public function unit(): string
    {
        return $this->usesTabs ? "\t" : str_repeat(' ', $this->indentSize);
    }

    /** Spaces per indent level (EditorConfig indent_size). */
    public function indentSize(): int
    {
        return $this->indentSize;
    }

    /** Display columns per hard tab (EditorConfig tab_width). */
    public function tabWidth(): int
    {
        return $this->tabWidth;
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
