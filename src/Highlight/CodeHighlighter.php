<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Porta\Support\LanguageMap;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Ansi\AnsiCodeTracker;
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
final class CodeHighlighter implements HighlightSink
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

    /** Consecutive backend faults before this buffer falls back to plain text. */
    private const int MAX_STRIKES = 3;

    private readonly TerminalTheme $syntaxTheme;

    /** @var string[] */
    private array $cache = [];
    private int $cacheVersion = -1;
    private ?string $language = null;

    /** Identifies this buffer in a request/answer pair; the file path once known. */
    private string $documentId = '';

    /** @var string[]|null raw lines matching the current cache */
    private ?array $lastRawLines = null;
    private int $staleEdits = 0;
    private int $fullParses = 0;
    private int $incrementalUpdates = 0;

    private SyntaxHighlightProvider $provider;

    /**
     * The version whose *content* produced {@see $cache}. Below
     * {@see $cacheVersion} the paint is provisional: an asynchronous backend
     * has been asked about a newer version and has not answered yet.
     */
    private int $resolvedVersion = -1;

    /** The version a backend is currently being asked about; -1 when idle. */
    private int $inFlightVersion = -1;

    /** @var string[]|null the lines that in-flight request carried */
    private ?array $inFlightLines = null;

    /** True while inside a provider call, to tell a reentrant (synchronous) delivery from a later one. */
    private bool $dispatching = false;

    /** Consecutive failures by the current backend; {@see MAX_STRIKES} trips it. */
    private int $strikes = 0;

    /** Set once a dispatch fails to answer within the call — the backend is asynchronous. */
    private bool $asyncBackend = false;

    private readonly \Closure $onRepaint;

    /**
     * @param SyntaxHighlightProvider|null $provider    the backend; defaults to the built-in
     *                                                  Tempest one. Injectable so tests can drive
     *                                                  the fallback paths, and so a plugin-supplied
     *                                                  engine can take over per language
     * @param TerminalTheme|null           $syntaxTheme the token color scheme; defaults to
     *                                                  the dark theme when none is injected
     * @param \Closure(): void|null        $onRepaint   called when an *asynchronous* delivery lands,
     *                                                  so the frame that shows it gets requested
     */
    public function __construct(?SyntaxHighlightProvider $provider = null, ?TerminalTheme $syntaxTheme = null, ?\Closure $onRepaint = null)
    {
        $this->syntaxTheme = $syntaxTheme ?? new DarkTerminalTheme();
        $this->provider = $provider ?? new TempestHighlightProvider($this->syntaxTheme);
        $this->onRepaint = $onRepaint ?? static function (): void {};
    }

    public function setFile(string $path): void
    {
        $this->documentId = $path;
        $this->language = LanguageMap::highlightForPath($path);
        $this->cacheVersion = -1;
        $this->cache = [];
        $this->lastRawLines = null;
        $this->staleEdits = 0;
        $this->resolvedVersion = -1;
        $this->inFlightVersion = -1;
        $this->inFlightLines = null;
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
        $this->resolvedVersion = -1;
        $this->inFlightVersion = -1;
        $this->inFlightLines = null;
    }

    /**
     * Re-color under a live theme switch. A backend that bakes the theme's
     * escape codes at construction has to rebuild itself (see
     * {@see ThemeAwareHighlightProvider}); one that reads the swapped
     * {@see SwitchableTerminalTheme} holder per token needs nothing. Either way
     * the cache is dropped, because it keys on the document version, not the theme.
     */
    public function refreshTheme(): void
    {
        if ($this->provider instanceof ThemeAwareHighlightProvider) {
            $this->provider->refreshTheme();
        }

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
            // The cap is applied here, so no backend is ever handed a huge file.
            $this->lastRawLines = null;

            return $this->cache = $lines;
        }

        /** @var string $language */
        $language = $this->language;
        $this->inFlightVersion = $version;
        $this->inFlightLines = $lines;
        $this->dispatching = true;

        try {
            $this->provider->highlight(new HighlightRequest($this->documentId, $language, $text, $version), $this);
        } catch (\Throwable $error) {
            $this->dispatching = false;
            $this->recordFailure($error);
            $this->inFlightVersion = -1;
            $this->lastRawLines = null;

            return $this->cache = $lines;
        } finally {
            $this->dispatching = false;
        }

        if ($this->resolvedVersion === $version) {
            return $this->cache; // a synchronous backend already delivered
        }

        $this->asyncBackend = true;

        // Asynchronous: paint provisionally until the answer lands. Carrying the
        // previous colors forward only works when the shape still matches.
        $this->lastRawLines = null;

        return $this->cache = \count($this->cache) === \count($lines) ? $this->cache : $lines;
    }

    /**
     * A backend's answer. Anything that isn't the request we are currently
     * waiting on is dropped — that one check covers a stale result, a request
     * we abandoned, and a delivery nobody asked for.
     */
    public function deliver(string $documentId, int $version, string $markup): void
    {
        if ($version !== $this->inFlightVersion) {
            return;
        }

        $lines = $this->inFlightLines ?? [];
        $result = $this->normalize($markup, \count($lines));
        if (null === $result) {
            // Mangled line structure is a backend fault, not a render error.
            $this->recordFailure(new \RuntimeException('highlighter changed the line count'));
            $this->inFlightVersion = -1;
            $this->cache = $lines;
            $this->lastRawLines = null;

            return;
        }

        $this->cache = $result;
        $this->cacheVersion = $version;
        $this->resolvedVersion = $version;
        $this->lastRawLines = $lines;
        $this->inFlightVersion = -1;
        $this->inFlightLines = null;
        $this->staleEdits = 0;
        $this->strikes = 0;
        ++$this->fullParses;

        if (!$this->dispatching) {
            // A late answer: nothing else will ask for a frame.
            ($this->onRepaint)();
        }
    }

    public function fail(string $documentId, int $version, \Throwable $error): void
    {
        if ($version !== $this->inFlightVersion) {
            return;
        }

        $this->recordFailure($error);
        $this->inFlightVersion = -1;
        $this->inFlightLines = null;
        $this->lastRawLines = null;
    }

    /**
     * Split markup into independently renderable lines, or null when the
     * backend broke the one contract core cannot paper over.
     *
     * @return string[]|null
     */
    private function normalize(string $markup, int $expectedLines): ?array
    {
        $hlLines = explode("\n", $markup);
        if (\count($hlLines) !== $expectedLines) {
            return null;
        }

        $tracker = new AnsiCodeTracker();
        $result = [];
        foreach ($hlLines as $line) {
            $prefix = $tracker->getActiveCodes();
            $tracker->processText($line);
            $result[] = $prefix.$line.($tracker->hasActiveCodes() ? Sgr::RESET : '');
        }

        return $result;
    }

    /**
     * Count a backend fault and, past {@see MAX_STRIKES} consecutive ones, stop
     * calling it for this buffer.
     *
     * Not time-based on purpose: a backend that keeps retrying and keeps
     * costing frame time is worse than one that stays off, and a timer-based
     * half-open state would make these tests clock-dependent. A successful
     * delivery resets the count; reopening the file gets a fresh backend.
     */
    private function recordFailure(\Throwable $error): void
    {
        // No guard for "already tripped": the plain backend echoes its input, so
        // it can neither throw nor change the line count, and cannot strike.
        ++$this->strikes;
        if ($this->strikes < self::MAX_STRIKES) {
            return;
        }

        $this->provider = new PlainTextHighlightProvider();
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

        // An asynchronous backend cannot colour a single line inside the frame,
        // so splicing would quietly leave edited lines uncoloured. Those buffers
        // repaint whole-document instead, which paint-then-refine already covers.
        if ($this->asyncBackend) {
            return null;
        }

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

        $capture = new CapturingHighlightSink();

        try {
            /** @var string $language */
            $language = $this->language;
            $this->provider->highlight(new HighlightRequest($this->documentId, $language, $line, $this->cacheVersion), $capture);
        } catch (\Throwable) {
            return $line;
        }

        // Null means the backend answers asynchronously, which this path cannot
        // use — it has to splice a colored line into the cache right now.
        $highlighted = $capture->markup;

        return null === $highlighted || str_contains($highlighted, "\n") ? $line : $highlighted;
    }
}
