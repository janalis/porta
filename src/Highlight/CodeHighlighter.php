<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Symfony\Component\Tui\Ansi\AnsiCodeTracker;
use Tempest\Highlight\Highlighter;
use Tempest\Highlight\TerminalTheme;

/**
 * Highlights a whole document and exposes it as per-display-line ANSI strings.
 *
 * The full text is highlighted in one pass (so multi-line tokens like
 * docblocks and heredocs are colored correctly), then split into lines.
 * An AnsiCodeTracker re-opens the SGR codes still active at each line start
 * so every line is independently renderable.
 *
 * Results are memoized on the document version; files above the size
 * threshold fall back to uncolored text to keep typing latency low.
 */
final class CodeHighlighter
{
    private const int MAX_HIGHLIGHT_BYTES = 512 * 1024;

    /**
     * Documents at or above this many lines use the incremental path for
     * local edits; below it a full parse stays comfortably within a frame.
     */
    public const int INCREMENTAL_MIN_LINES = 2000;

    /** A change is "local" when at most this many lines differ. */
    private const int MAX_LOCAL_CHANGE = 3;

    /**
     * Full "repair" parse after this many incremental updates, restoring
     * multi-line token fidelity (docblocks, heredocs) around past edits.
     */
    private const REPAIR_EVERY = 25;

    private Highlighter $highlighter;
    private readonly TerminalTheme $syntaxTheme;

    /** @var string[] */
    private array $cache = [];
    private int $cacheVersion = -1;
    private ?string $language = null;

    /** @var string[]|null raw lines matching the current cache */
    private ?array $lastRawLines = null;
    private int $staleEdits = 0;
    private int $fullParses = 0;
    private int $incrementalUpdates = 0;

    private const EXTENSION_MAP = [
        'php' => 'php',
        'phtml' => 'php',
        'js' => 'javascript',
        'mjs' => 'javascript',
        'ts' => 'typescript',
        'jsx' => 'javascript',
        'tsx' => 'typescript',
        'json' => 'json',
        'css' => 'css',
        'scss' => 'scss',
        'html' => 'html',
        'htm' => 'html',
        'xml' => 'xml',
        'twig' => 'twig',
        'blade' => 'blade',
        'md' => 'markdown',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'sql' => 'sql',
        'sh' => 'bash',
        'bash' => 'bash',
        'zsh' => 'bash',
        'env' => 'dotenv',
        'ini' => 'ini',
        'neon' => 'ini',
        'dockerfile' => 'dockerfile',
        'py' => 'python',
        'vue' => 'vue',
        'svelte' => 'svelte',
        'graphql' => 'graphql',
        'tf' => 'terraform',
        'diff' => 'diff',
        'patch' => 'diff',
    ];

    /** @var \Closure(string, string): string */
    private \Closure $parser;

    /**
     * @param (\Closure(string, string): string)|null $parser      (content, language) => highlighted;
     *                                                              injectable for testing the fallback paths
     * @param TerminalTheme|null                       $syntaxTheme the token color scheme; defaults to
     *                                                              the dark theme when none is injected
     */
    public function __construct(?\Closure $parser = null, ?TerminalTheme $syntaxTheme = null)
    {
        $this->syntaxTheme = $syntaxTheme ?? new DarkTerminalTheme();
        $this->highlighter = new Highlighter($this->syntaxTheme);
        $this->parser = $parser ?? fn (string $content, string $language): string => $this->highlighter->parse($content, $language);
    }

    public function setFile(string $path): void
    {
        $this->language = self::languageFor($path);
        $this->cacheVersion = -1;
        $this->cache = [];
        $this->lastRawLines = null;
        $this->staleEdits = 0;
    }

    /** The Tempest highlighting language for a file path, or null when unknown. */
    public static function languageFor(string $path): ?string
    {
        $name = strtolower(basename($path));
        $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return match (true) {
            'dockerfile' === $name => 'dockerfile',
            str_starts_with($name, '.env') => 'dotenv',
            default => self::EXTENSION_MAP[$ext] ?? null,
        };
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    /**
     * Drop the memoized highlight so the next {@see highlight()} re-parses. The
     * cache keys on the document version, not the theme, so a live theme switch
     * must bust it explicitly to re-color already-highlighted lines.
     */
    public function invalidate(): void
    {
        $this->cacheVersion = -1;
        $this->cache = [];
        $this->lastRawLines = null;
        $this->staleEdits = 0;
    }

    /**
     * Re-color under a live theme switch. Tempest bakes the theme's escape codes
     * when the Highlighter is constructed, so swapping the {@see SwitchableTerminalTheme}
     * inner is not enough — the Highlighter must be rebuilt (from the same holder,
     * which already points at the new scheme), then the cache dropped.
     */
    public function refreshTheme(): void
    {
        $this->highlighter = new Highlighter($this->syntaxTheme);
        $this->invalidate();
    }

    /**
     * @param string[] $lines Raw document lines
     *
     * @return string[] ANSI-highlighted lines (same count as input)
     */
    public function highlight(array $lines, int $version): array
    {
        if ($version === $this->cacheVersion) {
            return $this->cache;
        }

        $this->cacheVersion = $version;

        if (null === $this->language) {
            return $this->cache = $lines;
        }

        if (null !== ($incremental = $this->tryIncrementalUpdate($lines))) {
            $this->lastRawLines = $lines;

            return $this->cache = $incremental;
        }

        $text = implode("\n", $lines);
        if (\strlen($text) > self::MAX_HIGHLIGHT_BYTES) {
            $this->lastRawLines = null;

            return $this->cache = $lines;
        }

        try {
            /** @var string $language */
            $language = $this->language;
            $highlighted = ($this->parser)($text, $language);
        } catch (\Throwable) {
            $this->lastRawLines = null;

            return $this->cache = $lines;
        }

        $hlLines = explode("\n", $highlighted);
        if (\count($hlLines) !== \count($lines)) {
            // Highlighter mangled line structure; fall back to plain text
            $this->lastRawLines = null;

            return $this->cache = $lines;
        }

        $tracker = new AnsiCodeTracker();
        $result = [];
        foreach ($hlLines as $line) {
            $prefix = $tracker->getActiveCodes();
            $tracker->processText($line);
            $result[] = $prefix.$line.($tracker->hasActiveCodes() ? "\x1b[0m" : '');
        }

        ++$this->fullParses;
        $this->staleEdits = 0;
        $this->lastRawLines = $lines;

        return $this->cache = $result;
    }

    /**
     * Parse/update counters, for tests and debugging.
     *
     * @return array{fullParses: int, incrementalUpdates: int}
     */
    public function getStats(): array
    {
        return ['fullParses' => $this->fullParses, 'incrementalUpdates' => $this->incrementalUpdates];
    }

    /**
     * For a local edit in a large document, splice per-line-highlighted
     * replacements into the previous result instead of re-parsing everything.
     * Multi-line tokens crossing the edited lines may lose their color until
     * the next repair parse — the price of O(edit) instead of O(document)
     * per keystroke.
     *
     * @param string[] $lines
     *
     * @return string[]|null null when a full parse is required
     */
    private function tryIncrementalUpdate(array $lines): ?array
    {
        $old = $this->lastRawLines;
        $newCount = \count($lines);

        if (null === $old || $newCount < self::INCREMENTAL_MIN_LINES || abs($newCount - \count($old)) > self::MAX_LOCAL_CHANGE || $this->staleEdits >= self::REPAIR_EVERY) {
            return null;
        }

        $oldCount = \count($old);
        $maxPrefix = min($oldCount, $newCount);
        $prefix = 0;
        while ($prefix < $maxPrefix && $old[$prefix] === $lines[$prefix]) {
            ++$prefix;
        }
        $maxSuffix = min($oldCount, $newCount) - $prefix;
        $suffix = 0;
        while ($suffix < $maxSuffix && $old[$oldCount - 1 - $suffix] === $lines[$newCount - 1 - $suffix]) {
            ++$suffix;
        }

        $middleNew = $newCount - $prefix - $suffix;
        $middleOld = $oldCount - $prefix - $suffix;
        if ($middleNew > self::MAX_LOCAL_CHANGE || $middleOld > self::MAX_LOCAL_CHANGE) {
            return null;
        }

        $middle = [];
        for ($i = $prefix; $i < ($prefix + $middleNew); ++$i) {
            $middle[] = $this->highlightSingleLine($lines[$i]);
        }

        ++$this->incrementalUpdates;
        ++$this->staleEdits;

        return [
            ...\array_slice($this->cache, offset: 0, length: $prefix),
            ...$middle,
            ...($suffix > 0 ? \array_slice($this->cache, -$suffix) : []),
        ];
    }

    private function highlightSingleLine(string $line): string
    {
        if ('' === $line) {
            return '';
        }

        try {
            /** @var string $language */
            $language = $this->language;
            $highlighted = ($this->parser)($line, $language);
        } catch (\Throwable) {
            return $line;
        }

        return str_contains($highlighted, "\n") ? $line : $highlighted;
    }
}
