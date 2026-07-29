<?php

declare(strict_types=1);

use Porta\Highlight\TempestBackend;
use Porta\Plugin\PluginApi;
use Porta\Plugin\PortaPlugin;
use Tempest\Highlight\Highlighter;

/**
 * Syntax highlighting via `tempest/highlight`, Porta's in-process engine.
 *
 * Synchronous and fast enough for the typing path — a full parse of a
 * ~1300-line PHP file measures 6-16ms — so it answers before `highlight()`
 * returns and the editor paints in the same frame.
 *
 * The engine itself lives in `src/Highlight` ({@see TempestBackend}, which
 * registers Porta's house grammars for PHP docblocks, JSON and JSONC).
 * Deliberately: `tempest/highlight` is a composer requirement that the diff
 * view and project search also use directly, and code under `plugins/`
 * sits outside the 100% line-coverage gate. This file is the *registration* —
 * what makes tempest one selectable backend among others rather than the
 * hard-wired engine.
 *
 * It claims `*`: tempest falls back to its own plain-text grammar for a name
 * it does not know, so claiming everything means an unfamiliar language
 * renders uncoloured rather than falling through to nothing. A backend that
 * genuinely knows that language still wins it, either by configuration or by
 * loading earlier.
 *
 * It does **not** register token spans. Project search's comment/string
 * classification is core's own, so the scope filter keeps working whichever
 * highlighting backend is enabled — or none.
 */
return new class implements PortaPlugin {
    private ?Highlighter $highlighter = null;

    public function name(): string
    {
        return 'tempest';
    }

    public function activate(PluginApi $api): void
    {
        $theme = $api->syntaxTheme();
        $api->registerSetting('languages', 'Languages', 'text', '*', hint: 'comma-separated grammar names, or * for all');

        $claimed = static function () use ($api): array {
            $raw = trim((string) $api->setting('languages'));

            return '' === $raw ? [] : array_values(array_filter(array_map(trim(...), explode(',', $raw))));
        };
        $api->registerHighlighter('Tempest', $claimed, function (string $content, string $language, Closure $deliver) use ($theme): void {
            // Built on first use: the theme holder is swapped in place on a
            // live theme switch, but tempest bakes its escape codes at
            // construction, so the Highlighter has to outlive nothing.
            $this->highlighter ??= TempestBackend::newHighlighter($theme);
            $deliver($this->highlighter->parse($content, $language));
        });

        // Tempest bakes the theme's escape codes when the Highlighter is built,
        // so swapping the holder is not enough — drop the memo and let the next
        // paint rebuild. Core drops the highlight caches after this runs.
        $api->onThemeChanged(function (): void {
            $this->highlighter = null;
        });
    }
};
