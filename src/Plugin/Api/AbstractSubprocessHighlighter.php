<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Support\AsyncProcess;
use Revolt\EventLoop;
use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * The base for a highlighting backend that is an external process emitting a
 * token stream (chroma). On top of {@see AbstractToolPlugin} it owns the whole
 * mechanism of being asynchronous on the typing path:
 *
 *   - **debounce** — one pending timer per language, and a newer request
 *     REPLACES a pending one outright rather than queueing: intermediate
 *     keystrokes are worthless once a later version exists;
 *   - **latest-wins** — every dispatch carries a sequence number, and an answer
 *     for anything but the newest is dropped (core drops superseded deliveries
 *     too, but not spending the repaint is cheaper);
 *   - **a concurrency ceiling** — at most {@see MAX_CONCURRENT} processes across
 *     every language, because a subprocess per keystroke per pane is how a
 *     highlighter becomes a fork bomb;
 *   - **the size guard** — over the `maxBytes` setting the request is dropped
 *     and Porta keeps its current paint;
 *   - **the token stream → ANSI conversion**, including the longest-prefix type
 *     match that Pygments-style flattened names ('NameFunction' is
 *     'Name.Function') need.
 *
 * The subclass supplies only its tool's truth: the lexer argv and the
 * prefix → token-type table. Everything above is here rather than in the plugin
 * file because plugin files sit outside the coverage gate AND outside mago's
 * analyzer — a scheduler is exactly the kind of code that must not.
 *
 * A **synchronous** backend does not belong here: it should call `$deliver`
 * before returning (see the bundled tempest plugin, which is a plain
 * {@see AbstractPlugin}).
 */
abstract class AbstractSubprocessHighlighter extends AbstractToolPlugin implements DisposablePlugin
{
    /** Hard ceiling on concurrent tool processes across every language. */
    private const int MAX_CONCURRENT = 2;

    /** @var array<string, string> language => pending debounce timer id */
    private array $timers = [];

    /** @var array<string, int> language => newest dispatched sequence */
    private array $latest = [];

    private int $sequence = 0;

    private int $running = 0;

    /**
     * The tool's arguments for one document — the language and the lexer
     * selection; the text arrives on stdin.
     *
     * @return list<string>
     */
    abstract protected function lexerArgs(string $language): array;

    /**
     * This tool's token-type names mapped to Porta's, matched by LONGEST PREFIX
     * FIRST (so the array order is meaningful: 'LiteralString' must come after
     * 'LiteralStringEscape'). A name no prefix matches renders plain.
     *
     * @return array<string, TokenTypeEnum>
     */
    abstract protected function tokenMap(): array;

    /**
     * The whole registration for a subprocess highlighter: the three settings
     * rows every one of them needs, plus the backend itself wired to the
     * inherited claim list and availability predicate.
     */
    final protected function registerSubprocessHighlighter(
        PluginApi $api,
        string $label,
        string $languages,
        int $debounceMs = 250,
        int $maxBytes = 262_144,
    ): void {
        $api->registerSetting('languages', 'Languages', 'text', $languages, hint: 'comma-separated grammar names, or * for all');
        // 0 is meaningful for both: no debounce, and claim nothing.
        $api->registerSetting('debounce', 'Debounce (ms)', 'int', $debounceMs, min: 0, max: 5_000, step: 10);
        $api->registerSetting('maxBytes', 'Skip files larger than (bytes)', 'int', $maxBytes, min: 0, step: 65_536);

        $theme = $api->syntaxTheme();
        $api->registerHighlighter(
            $label,
            fn (): array => $this->claimedLanguages($api),
            function (string $text, string $language, \Closure $deliver) use ($api, $theme): void {
                $this->schedule($api, $theme, $text, $language, $deliver);
            },
            isAvailable: $this->binaryAvailable($api),
        );
    }

    /**
     * A live disable must kill the pending debounce timers: the registry drops
     * the highlighter, but a timer already armed would still fire and spawn a
     * subprocess for a plugin the user just turned off. In-flight processes
     * are left to their own timeout — they only decrement the ceiling on exit,
     * and their delivery is dropped by the registry removal anyway.
     */
    #[\Override]
    public function deactivate(): void
    {
        foreach ($this->timers as $timer) {
            EventLoop::cancel($timer);
        }
        $this->timers = [];
        $this->latest = [];
    }

    /** Debounce this request, replacing any pending one for the same language. */
    private function schedule(PluginApi $api, TerminalTheme $theme, string $text, string $language, \Closure $deliver): void
    {
        if (\strlen($text) > (int) $api->setting('maxBytes')) {
            return; // too big to be worth a subprocess; Porta keeps its current paint
        }

        $pending = $this->timers[$language] ?? null;
        if (null !== $pending) {
            EventLoop::cancel($pending);
        }

        $sequence = ++$this->sequence;
        $this->latest[$language] = $sequence;

        $delay = max(0, (int) $api->setting('debounce')) / 1000;
        $this->timers[$language] = EventLoop::delay($delay, function () use ($api, $theme, $text, $language, $deliver, $sequence): void {
            unset($this->timers[$language]);
            $this->run($api, $theme, $text, $language, $deliver, $sequence);
        });
    }

    /** One tool run, unless the binary is missing or the ceiling is already reached. */
    private function run(PluginApi $api, TerminalTheme $theme, string $text, string $language, \Closure $deliver, int $sequence): void
    {
        $binary = $this->binary($api);
        if (null === $binary || $this->running >= self::MAX_CONCURRENT) {
            return;
        }

        ++$this->running;
        new AsyncProcess([$binary, ...$this->lexerArgs($language)], $api->projectRoot(), stdin: $text, timeout: AsyncProcess::INTERACTIVE_TOOL_TIMEOUT)
            ->start(function (int $code, string $stdout) use ($theme, $language, $deliver, $sequence): void {
                --$this->running;
                if (0 !== $code || ($this->latest[$language] ?? 0) !== $sequence) {
                    return; // failed, or a newer version superseded this run
                }
                $markup = $this->render($stdout, $theme);
                if (null !== $markup) {
                    $deliver($markup);
                }
            });
    }

    /**
     * The tool's JSON token stream as ANSI, or null when it is not usable — a
     * null keeps the previous paint instead of blanking the buffer. Token
     * values are expected to concatenate back to the source byte for byte,
     * which is what lets this ignore offsets entirely.
     */
    private function render(string $json, TerminalTheme $theme): ?string
    {
        try {
            /** @var array<array-key, mixed>|scalar|null $tokens */
            $tokens = json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($tokens)) {
            return null;
        }

        $markup = '';
        /** @var mixed $token */
        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                return null;
            }
            $value = \is_string($token['value'] ?? null) ? $token['value'] : '';
            $type = $this->tokenType(\is_string($token['type'] ?? null) ? $token['type'] : '');
            $markup .= null === $type ? $value : $theme->before($type).$value.$theme->after($type);
        }

        return $markup;
    }

    /** The mapped token type for a flattened type name, by longest prefix. */
    private function tokenType(string $name): ?TokenTypeEnum
    {
        foreach ($this->tokenMap() as $prefix => $type) {
            if (str_starts_with($name, $prefix)) {
                return $type;
            }
        }

        return null; // unmapped (Text, Error, Other, …): leave it plain
    }
}
