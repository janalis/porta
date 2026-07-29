<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * The base every bundled plugin extends: it owns `name()` so a plugin file
 * never spends four lines returning a literal, and carries the handful of
 * helpers that are genuinely shared across plugin KINDS (a viewer and a
 * highlighter have little else in common).
 *
 * Lives in `src/` on purpose — the registration-only doctrine says plugin
 * files wire and `src/` implements, which also drags this logic under the
 * coverage gate and mago's analyzer, where the copies in ten plugin files
 * never were.
 *
 * Third-party plugins may extend it too, but they are equally free to
 * implement {@see PortaPlugin} directly: the interface is the contract, this
 * is convenience.
 */
abstract class AbstractPlugin implements PortaPlugin
{
    public function __construct(
        private readonly string $name,
    ) {}

    final public function name(): string
    {
        return $this->name;
    }

    /**
     * A comma-separated claim list from one of this plugin's own settings —
     * the shape both highlighter plugins use for their `languages` row
     * (`'*'` claims everything; blanks and stray spaces are dropped).
     *
     * @return list<string>
     */
    protected function claimedLanguages(PluginApi $api, string $key = 'languages'): array
    {
        $raw = trim((string) $api->setting($key));
        if ('' === $raw) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $raw))));
    }

    /**
     * A CORE IDE setting, read from the env channel the container itself
     * reads ({@see \Porta\Container\IdeContainerFactory} seeds `PORTA_*`).
     *
     * Deliberately narrow and bundled-plugin territory: {@see PluginApi} can
     * only read settings a plugin registered itself, and duplicating an IDE
     * setting as a plugin one would give the user two knobs for one thing
     * (the graph viewer needs the core `dotPath`). A third-party plugin has
     * no business here — it cannot know which keys exist.
     */
    protected function coreSetting(string $name): ?string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', replacement: '_$0', subject: $name) ?? $name;
        $value = $_ENV['PORTA_'.strtoupper($snake)] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
