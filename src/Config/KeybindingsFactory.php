<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Plugin\Runtime\PluginHost;
use Symfony\Component\Tui\Exception\InvalidArgumentException;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Input\KeyParser;

/**
 * Builds the immutable Keybindings map: IDE defaults, overlaid with plugin
 * default keys (which is why creating it forces the plugin load), overlaid
 * with the platform's keymap ({@see PlatformKeymap}), overlaid with the
 * user's .porta.json keymap. Registered as the container's Keybindings
 * factory.
 *
 * Keybindings throws on a key id it cannot read, so the two layers the
 * user and third parties write — plugin keys and the keymap — are vetted
 * first: a typo costs its binding and a warning, never the boot.
 */
final class KeybindingsFactory
{
    public function __construct(
        private readonly PluginHost $plugins,
        private readonly IdeConfig $config,
        private readonly ActionRegistry $actions,
        /** The platform detected at boot ({@see KeymapPlatform::detect()}), which the `keymapPlatform` setting may override. */
        private readonly string $hostKeymap = KeymapPlatform::Linux->value,
    ) {}

    /** @var array<string, true> action ids, and "action key" pairs, already reported — rebuild() re-runs this on every keymap edit */
    private array $reported = [];

    /**
     * @param ?KeyParser                        $parser LiveKeybindings::rebuild() threads the live parser through (the kitty negotiation lives on it); null builds the boot default
     * @param ?array<string, list<string>>      $keymap a fresher user keymap than the boot config's (LiveKeybindings re-reads .porta.json); null uses the boot snapshot
     * @param ?string                           $platform a fresher `keymapPlatform` setting than the boot config's; null uses the boot snapshot
     * @param list<string>                      $arrived  the Cmd chords that have reached the IDE ({@see PlatformKeymap::overrides()})
     */
    public function create(?KeyParser $parser = null, ?array $keymap = null, ?string $platform = null, array $arrived = []): Keybindings
    {
        $this->plugins->ensureLoaded();
        $keymap ??= $this->config->keymap;
        $parser ??= new KeyParser();

        return new Keybindings(\array_merge(
            ActionCatalog::ideDefaults(),
            $this->readable($this->plugins->manager->defaultKeys, $parser),
            PlatformKeymap::overrides($this->platform($platform), $arrived),
            $this->readable($this->known($keymap), $parser),
        ), $parser);
    }

    /**
     * The platform whose keymap applies: the `keymapPlatform` setting — the
     * one given, else the boot config's — or the detected one for `auto`.
     */
    public function platform(?string $setting = null): KeymapPlatform
    {
        return KeymapPlatform::resolve(
            $setting ?? (string) ($this->config->settings['keymapPlatform'] ?? KeymapPlatform::AUTO),
            KeymapPlatform::tryFrom($this->hostKeymap) ?? KeymapPlatform::Linux,
        );
    }

    /**
     * The user's keymap minus the ids nothing declares, each reported through
     * the plugin channel's boot warnings.
     *
     * This is the only place the question can be asked: the catalog is core's
     * plus every loaded plugin's, and `ensureLoaded()` has just run. Namespaced
     * ids ("plugin:command") are plugin COMMANDS, dispatched by id rather than
     * bound to a descriptor, so they are never checked — they simply bind if
     * their plugin loads.
     *
     * @param  array<string, list<string>> $keymap
     * @return array<string, list<string>>
     */
    private function known(array $keymap): array
    {
        $kept = [];
        foreach ($keymap as $action => $keys) {
            if (!\str_contains($action, ':') && !$this->actions->has($action)) {
                if (!\array_key_exists($action, $this->reported)) {
                    $this->reported[$action] = true;
                    $this->plugins->manager->addWarning(new ConfigWarning('Unknown keymap action "%action%" in .porta.json', ['%action%' => $action]));
                }

                continue;
            }
            $kept[$action] = $keys;
        }

        return $kept;
    }

    /**
     * $bindings minus the key ids $parser cannot read, each reported once.
     * An action whose every key was unreadable is dropped whole, so the
     * layer beneath still binds it; only a deliberate `[]` unbinds.
     *
     * @param  array<string, list<string>> $bindings
     * @return array<string, list<string>>
     */
    private function readable(array $bindings, KeyParser $parser): array
    {
        foreach ($bindings as $action => $keys) {
            $readable = \array_values(\array_filter($keys, function (string $key) use ($action, $parser): bool {
                try {
                    new Keybindings([$action => [$key]], $parser);

                    return true;
                } catch (InvalidArgumentException) {
                    if (!\array_key_exists($action.' '.$key, $this->reported)) {
                        $this->reported[$action.' '.$key] = true;
                        $this->plugins->manager->addWarning(new ConfigWarning('Invalid key "%key%" bound to "%action%"', ['%key%' => $key, '%action%' => $action]));
                    }

                    return false;
                }
            }));
            if ([] === $readable && [] !== $keys) {
                unset($bindings[$action]);

                continue;
            }
            $bindings[$action] = $readable;
        }

        return $bindings;
    }
}
