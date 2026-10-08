<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Porta\Support\LanguageMap;
use Porta\Support\Scheduler;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Ansi\AnsiCodeTracker;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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
    private const int REPAIR_EVERY = 25;

    /** Consecutive backend faults before this buffer falls back to plain text. */
    private const int MAX_STRIKES = 3;

    /**
     * How long an asynchronous backend has to answer before the request is
     * abandoned. Generous next to chroma's measured 40-70ms — this is a
     * liveness backstop for a backend that hangs, not a latency target.
     */
    private const float ANSWER_TIMEOUT_SECONDS = 2.0;

    /**
     * A CSI whose parameter bytes run into another ESC — i.e. an escape that
     * never terminated. Everything after it renders as text.
     */
    private const string MALFORMED_ESCAPE = '/\x1b\[[0-9;]*\x1b/';

    /**
     * The repairable form of it: a whole sequence sitting between `ESC [` and
     * the parameters that belong to it. The capture is those parameters.
     */
    private const string SPLIT_ESCAPE = '/\x1b\[\x1b\[[0-9;]*m([0-9;]*m)/';

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

    /** @var \Closure(bool): void */
    private readonly \Closure $onParse;

    /** Timer id for the in-flight request's answer deadline; null when none is armed. */
    private ?string $timeoutTimer = null;

    /**
     * @param SyntaxHighlightProvider|null $provider    the backend; defaults to the built-in
     *                                                  Tempest one. Injectable so tests can drive
     *                                                  the fallback paths, and so a plugin-supplied
     *                                                  engine can take over per language
     * @param TerminalTheme|null           $syntaxTheme the token color scheme; defaults to
     *                                                  the dark theme when none is injected
     * @param (\Closure(): void)|null        $onRepaint   called when an *asynchronous* delivery lands,
     *                                                  so the frame that shows it gets requested
     * @param (\Closure(bool): void)|null    $onParse     told after each completed parse whether it
     *                                                  was an incremental splice (true) or a full
     *                                                  parse (false) — the perf beat's evidence
     *                                                  for "typing re-parses the whole file"
     */
    public function __construct(
        ?SyntaxHighlightProvider $provider = null,
        ?TerminalTheme $syntaxTheme = null,
        ?\Closure $onRepaint = null,
        private readonly ?Scheduler $scheduler = null,
        ?\Closure $onParse = null,
    ) {
        $this->provider = $provider ?? new TempestHighlightProvider($syntaxTheme ?? new DarkTerminalTheme());
        $this->onRepaint = $onRepaint ?? static function (): void {};
        $this->onParse = $onParse ?? static function (): void {};
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

    /**
     * Highlight as $language under the id $documentId, for text that is no
     * file: a fenced code block in a markdown page.
     */
    public function setLanguage(string $documentId, ?string $language): void
    {
        $this->setFile($documentId);
        $this->language = $language;
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

        $text = \implode("\n", $lines);
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
        } catch (\Throwable) {
            $this->dispatching = false;
            $this->recordFailure();
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
        $this->armAnswerDeadline($version);

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
    #[\Override]
    public function deliver(string $documentId, int $version, string $markup): void
    {
        if ($version !== $this->inFlightVersion) {
            return;
        }

        $lines = $this->inFlightLines ?? [];
        $result = $this->normalize($markup, $lines);
        if (null === $result) {
            // Mangled line structure is a backend fault, not a render error.
            $this->recordFailure();
            $this->inFlightVersion = -1;
            $this->cache = $lines;
            $this->lastRawLines = null;

            return;
        }

        $this->disarmAnswerDeadline();
        $this->cache = $result;
        $this->cacheVersion = $version;
        $this->resolvedVersion = $version;
        $this->lastRawLines = $lines;
        $this->inFlightVersion = -1;
        $this->inFlightLines = null;
        $this->staleEdits = 0;
        $this->strikes = 0;
        ++$this->fullParses;
        ($this->onParse)(false);

        if (!$this->dispatching) {
            // A late answer: nothing else will ask for a frame.
            ($this->onRepaint)();
        }
    }

    #[\Override]
    public function fail(string $documentId, int $version, \Throwable $error): void
    {
        if ($version !== $this->inFlightVersion) {
            return;
        }

        $this->disarmAnswerDeadline();
        $this->recordFailure();
        $this->inFlightVersion = -1;
        $this->inFlightLines = null;
        $this->lastRawLines = null;
    }

    /**
     * Give the in-flight request a deadline.
     *
     * The strike counter alone only catches a backend that *fails*; one that
     * dispatches and never answers would leave the buffer provisionally painted
     * and re-dispatch on every edit forever. On expiry the request is forgotten,
     * so an answer arriving later is dropped by the ordinary staleness check.
     */
    private function armAnswerDeadline(int $version): void
    {
        $scheduler = $this->scheduler;
        if (null === $scheduler) {
            return;
        }

        $this->disarmAnswerDeadline();
        $this->timeoutTimer = $scheduler->delay(self::ANSWER_TIMEOUT_SECONDS, function () use ($version): void {
            $this->timeoutTimer = null;
            if ($version !== $this->inFlightVersion) {
                return;
            }
            $this->recordFailure();
            $this->inFlightVersion = -1;
            $this->inFlightLines = null;
        });
    }

    private function disarmAnswerDeadline(): void
    {
        if (null === $this->timeoutTimer) {
            return;
        }

        $this->scheduler?->cancel($this->timeoutTimer);
        $this->timeoutTimer = null;
    }

    /**
     * Split markup into independently renderable lines, rendering from the FILE
     * any line whose markup does not reduce back to it, or null when the line
     * COUNT is wrong — the one fault core cannot paper over at all.
     *
     * `tempest/highlight` gets this wrong two ways, both silent:
     *
     * - A SPLIT escape on some attribute lines — `ESC [`, then a whole other
     *   sequence, then the orphaned tail `39m`. The tail is not a colour, it is
     *   visible TEXT, so the row reads `#[Autowire(service39m: …` and measures
     *   five columns wider than its content. That shape is repaired, then
     *   re-checked like everything else.
     * - Its `Base` language strips `{+ … +}` diff markers from EVERY language,
     *   so a PHP line containing them renders four characters shorter than the
     *   file. Nothing in Porta wants that: the git diff view has its own
     *   renderer, and the only thing that ever asked for it was a test
     *   characterising the upstream behaviour.
     *
     * Either way the row no longer says what the file says, and every
     * column-placed decoration on it lands somewhere else. In an editor, being
     * able to trust the text beats colouring it, so the file wins.
     *
     * One line, not the file: lines are independently renderable by
     * construction here, so a backend that breaks one costs its own colours and
     * nothing else's.
     *
     * @param array<array-key, string> $sourceLines the lines the markup was made from
     *
     * @return string[]|null
     */
    private function normalize(string $markup, array $sourceLines): ?array
    {
        $hlLines = \explode("\n", $markup);
        if (\count($hlLines) !== \count($sourceLines)) {
            return null;
        }

        $tracker = new AnsiCodeTracker();
        $result = [];
        foreach ($hlLines as $index => $line) {
            $source = $sourceLines[$index] ?? '';
            if (1 === \preg_match(self::MALFORMED_ESCAPE, $line)) {
                $line = self::repair($line, $source) ?? $source;
            }

            // The contract, finally enforced: markup must reduce to its own
            // source. A line that fails is rendered from the FILE, and is NOT
            // fed to the tracker — its codes are the thing being distrusted, so
            // carrying them onward would spread the fault rather than contain
            // it.
            if (AnsiUtils::stripAnsiCodes($line) !== $source) {
                $result[] = $source;
                continue;
            }

            $prefix = $tracker->getActiveCodes();
            $tracker->processText($line);
            $result[] = $prefix.$line.($tracker->hasActiveCodes() ? Sgr::RESET : '');
        }

        return $result;
    }

    /**
     * Put a split escape back together, or null when the result cannot be
     * trusted.
     *
     * The fault has a recoverable shape: a complete sequence has been INSERTED
     * between `ESC [` and the parameters it was meant to carry, so
     * `ESC [ ESC [38;5;117m 39m` was `ESC [39m` before something got in the way.
     * Dropping the inserted sequence restores it exactly.
     *
     * The repair is then CHECKED rather than assumed: it is only used when the
     * result reduces to the line's own source. That is a guess about a
     * third-party bug's shape, and a guess that paints the wrong thing is worse
     * than one that declines — so when the check fails the caller renders the
     * line plain, which is always safe.
     */
    private static function repair(string $line, string $source): ?string
    {
        $fixed = \preg_replace(self::SPLIT_ESCAPE, replacement: "\x1b[$1", subject: $line);
        if (null === $fixed || 1 === \preg_match(self::MALFORMED_ESCAPE, $fixed)) {
            return null;
        }

        return AnsiUtils::stripAnsiCodes($fixed) === $source ? $fixed : null;
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
    private function recordFailure(): void
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

        if (null === $old || $newCount < self::INCREMENTAL_MIN_LINES || \abs($newCount - \count($old)) > self::MAX_LOCAL_CHANGE || $this->staleEdits >= self::REPAIR_EVERY) {
            return null;
        }

        $oldCount = \count($old);
        $maxPrefix = \min($oldCount, $newCount);
        $prefix = 0;
        while ($prefix < $maxPrefix && $old[$prefix] === $lines[$prefix]) {
            ++$prefix;
        }
        $maxSuffix = \min($oldCount, $newCount) - $prefix;
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
        ($this->onParse)(true);

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

        return null === $highlighted || \str_contains($highlighted, "\n") ? $line : $highlighted;
    }
}
