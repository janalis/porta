<?php

declare(strict_types=1);

namespace Porta\Plugin;

use Porta\Editor\OpenFocus;
use Porta\Lsp\Uri;

/**
 * A plugin's handle into Porta. Registration methods buffer during
 * `activate()` and land atomically when it returns (a throwing plugin
 * contributes nothing). Action methods reach the live IDE through the
 * manager's runtime and are meant for command handlers / event hooks —
 * calling them during `activate()` throws a {@see PluginException}.
 */
final class PluginApi
{
    private const string ID_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/i';
    private const array SETTING_KINDS = ['bool', 'choice', 'text', 'int'];

    /** @var list<array{plugin: string, id: string, label: string, handler: \Closure(): void, keys: list<string>}> */
    private array $commands = [];
    /** @var array<string, true> registered short ids, for duplicate detection */
    private array $ids = [];
    /** @var list<array{id: string, when: (\Closure(): bool)|null}> namespaced command ids shown in the Plugins menu */
    private array $menuItems = [];
    /** @var list<array{0: string, 1: \Closure(string): void}> [event, listener] */
    private array $hooks = [];
    /** @var array<string, array{key: string, label: string, kind: string, default: bool|int|string, options: list<string>|null, hint: string|null}> by key */
    private array $settingDescriptors = [];

    public function __construct(
        private readonly string $pluginName,
        private readonly PluginManager $manager,
        private readonly string $rootDir,
        private readonly PluginSettings $settings,
    ) {}

    /**
     * The open project's root directory — static config, safe during
     * activate() (e.g. to locate `vendor/bin/...` tools).
     */
    public function projectRoot(): string
    {
        return $this->rootDir;
    }

    // --- Registration (buffered until the loader commits) --------------------

    /**
     * Register a palette command, optionally with default keybindings
     * (specs like 'ctrl+alt+y'; rebindable via .porta.json under
     * "<plugin>:<id>"). The handler runs wrapped — a throw flashes instead of
     * crashing the IDE.
     *
     * @param \Closure(): void $handler
     * @param list<string>     $keys
     */
    public function registerCommand(string $id, string $label, \Closure $handler, array $keys = []): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid command id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        if (array_key_exists($id, $this->ids)) {
            throw new PluginException(\sprintf('Duplicate command id "%s"', $id));
        }
        $this->ids[$id] = true;
        $this->commands[] = [
            'plugin' => $this->pluginName,
            'id' => $this->pluginName.':'.$id,
            'label' => $label,
            'handler' => $handler,
            'keys' => array_values($keys),
        ];
    }

    /**
     * Show a registered command in the Plugins menu (its label is reused).
     * With $enabledWhen, the item renders disabled while the closure returns
     * false (e.g. the tool's binary is missing) — it is re-evaluated on every
     * menu render, and a throw reads as disabled.
     *
     * @param (\Closure(): bool)|null $enabledWhen
     */
    public function addMenuItem(string $commandId, ?\Closure $enabledWhen = null): void
    {
        if (!array_key_exists($commandId, $this->ids)) {
            throw new PluginException(\sprintf('Unknown command id "%s" for a menu item — register it first', $commandId));
        }
        $this->menuItems[] = ['id' => $this->pluginName.':'.$commandId, 'when' => $enabledWhen];
    }

    /**
     * Declare a user-editable setting, shown on this plugin's Settings page
     * and persisted in `.porta.json` under `plugins.<name>.<key>`. Call it
     * before {@see setting()} — the descriptor supplies the default and the
     * type coercion. Kinds: 'text', 'bool', 'int', 'choice' (with $options).
     *
     * @param list<string>|null $options required for 'choice'; must contain the default
     */
    public function registerSetting(string $key, string $label, string $kind = 'text', bool|int|string $default = '', ?array $options = null, ?string $hint = null): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $key)) {
            throw new PluginException(\sprintf('Invalid setting key "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $key));
        }
        if (array_key_exists($key, $this->settingDescriptors)) {
            throw new PluginException(\sprintf('Duplicate setting key "%s"', $key));
        }
        if (!\in_array($kind, self::SETTING_KINDS, strict: true)) {
            throw new PluginException(\sprintf('Unknown setting kind "%s" (expected bool, choice, text or int)', $kind));
        }
        if ('choice' === $kind && (null === $options || [] === $options || !\in_array($default, $options, strict: true))) {
            throw new PluginException(\sprintf('Setting "%s": a choice needs options containing its default', $key));
        }
        $this->settingDescriptors[$key] = [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'default' => $default,
            'options' => $options,
            'hint' => $hint,
        ];
    }

    /**
     * The current value of a setting this plugin registered — the user's
     * `.porta.json`/Settings-page value coerced to the declared kind, or the
     * default. Safe during activate() (the config loads before plugins), but
     * {@see registerSetting()} must have run first.
     */
    public function setting(string $key): bool|int|string
    {
        $descriptor = $this->settingDescriptors[$key] ?? throw new PluginException(\sprintf('Unknown setting "%s" — registerSetting() it first', $key));
        $raw = $this->settings->raw($this->pluginName, $key);
        if (null === $raw) {
            return $descriptor['default'];
        }

        return match ($descriptor['kind']) {
            'bool' => (bool) $raw,
            'int' => (int) $raw,
            'choice' => \in_array((string) $raw, $descriptor['options'] ?? [], strict: true) ? (string) $raw : $descriptor['default'],
            default => (string) $raw,
        };
    }

    /** @param \Closure(string): void $listener receives the absolute path */
    public function onFileOpened(\Closure $listener): void
    {
        $this->hooks[] = ['fileOpened', $listener];
    }

    /** @param \Closure(string): void $listener receives the absolute path */
    public function onFileSaved(\Closure $listener): void
    {
        $this->hooks[] = ['fileSaved', $listener];
    }

    /** @param \Closure(string): void $listener receives the absolute path */
    public function onBufferSwitched(\Closure $listener): void
    {
        $this->hooks[] = ['bufferSwitched', $listener];
    }

    // --- Actions (live IDE; PluginException during activate()) ---------------

    /** Show a transient status-bar message. */
    public function flash(string $message, float $seconds = 2.5): void
    {
        $runtime = $this->manager->runtime();
        $runtime->notifier->flash($message, $seconds);
        $runtime->tui->requestRender();
    }

    /**
     * Persist a new value for a setting this plugin registered — coerced to the
     * declared kind, kept live and written to `.porta.json` under
     * `plugins.<name>.<key>`, exactly like a Settings-page edit. An action
     * method: calling it during activate() throws (seed defaults via
     * {@see registerSetting()} instead).
     */
    public function changeSetting(string $key, bool|int|string $value): void
    {
        if (!array_key_exists($key, $this->settingDescriptors)) {
            throw new PluginException(\sprintf('Unknown setting "%s" — registerSetting() it first', $key));
        }
        $runtime = $this->manager->runtime(); // activate()-phase gate
        $this->settings->change($this->pluginName, $key, $value);
        $runtime->tui->requestRender();
    }

    /** The active file's absolute path, or null (no file / an image tab). */
    public function activeFile(): ?string
    {
        $path = $this->manager->runtime()->editor->getFilePath();

        return null !== $path && '' !== $path ? $path : null;
    }

    /** The active text document's full contents, or null when none is active. */
    public function documentText(): ?string
    {
        $runtime = $this->manager->runtime();

        return null !== $this->activeFile() ? $runtime->editor->getDocument()->getText() : null;
    }

    /**
     * The caret position in the active text file (0-based line and byte column).
     *
     * @return array{line: int, column: int}|null
     */
    public function caretPosition(): ?array
    {
        $runtime = $this->manager->runtime();
        if (null === $this->activeFile()) {
            return null;
        }
        $document = $runtime->editor->getDocument();

        return ['line' => $document->getCursorLine(), 'column' => $document->getCursorCol()];
    }

    /** Insert text at the caret (undoable, LSP-synced). No-op without a text file. */
    public function insertAtCaret(string $text): void
    {
        $runtime = $this->manager->runtime();
        if (null === $this->activeFile()) {
            return;
        }
        $runtime->editor->insertText($text);
        $runtime->tui->requestRender();
    }

    /** Open a file (absolute path) and focus the editor. */
    public function openFile(string $path): void
    {
        $this->manager->runtime()->files->openFile($path, focus: OpenFocus::Editor);
    }

    /** Move the caret (0-based line, UTF-16 column) in the active file. */
    public function jumpTo(int $line, int $column = 0): void
    {
        $runtime = $this->manager->runtime();
        $runtime->editor->jumpTo($line, $column);
        $runtime->tui->requestRender();
    }

    /**
     * Publish diagnostics for an open file — they render exactly like LSP
     * diagnostics (inline messages, gutter/stripe colors, the Problems panel)
     * and replace any previous set for that file, so an empty list clears
     * them. A path with no open buffer is a silent no-op.
     *
     * @param list<array{line: int, message: string, severity?: int, column?: int}> $diagnostics 0-based line; severity 1 = error (default), 2 = warning
     */
    public function setDiagnostics(string $path, array $diagnostics): void
    {
        $runtime = $this->manager->runtime();
        $byLine = [];
        foreach ($diagnostics as $diagnostic) {
            $column = max(0, $diagnostic['column'] ?? 0);
            $byLine[max(0, $diagnostic['line'])][] = [
                'severity' => $diagnostic['severity'] ?? 1,
                'message' => $diagnostic['message'],
                'col' => $column,
                'endCol' => $column,
            ];
        }
        $runtime->diagnostics->applyDiagnostics(Uri::fromPath($path), $byLine);
        $runtime->tui->requestRender();
    }

    // --- Loader/manager plumbing ---------------------------------------------

    public function pluginName(): string
    {
        return $this->pluginName;
    }

    /** @return list<array{plugin: string, id: string, label: string, handler: \Closure(): void, keys: list<string>}> */
    public function bufferedCommands(): array
    {
        return $this->commands;
    }

    /** @return list<array{id: string, when: (\Closure(): bool)|null}> */
    public function bufferedMenuItems(): array
    {
        return $this->menuItems;
    }

    /** @return list<array{0: string, 1: \Closure(string): void}> */
    public function bufferedHooks(): array
    {
        return $this->hooks;
    }

    /** @return list<array{key: string, label: string, kind: string, default: bool|int|string, options: list<string>|null, hint: string|null}> */
    public function bufferedSettings(): array
    {
        return array_values($this->settingDescriptors);
    }
}
