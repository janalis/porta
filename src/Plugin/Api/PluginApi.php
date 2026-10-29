<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Config\ActionCatalog;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\OpenFocus;
use Porta\Event\ActiveBufferChanged;
use Porta\Event\BufferOpened;
use Porta\Event\BufferReloaded;
use Porta\Event\BufferSaved;
use Porta\Event\FileRewritten;
use Porta\Event\IdeReady;
use Porta\Event\IdeShuttingDown;
use Porta\Event\PluginObservable;
use Porta\Event\ThemeChanged;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Lsp\Uri;
use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginMenuSpec;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Plugin\Tool\ToolLocator;
use Porta\Plugin\Tool\ToolManager;
use Porta\Plugin\Tool\ToolRequirement;
use Porta\Settings\SettingsRowKind;
use Porta\Support\ProjectPaths;
use Porta\Ui\Markup\Markup;
use Porta\Ui\TextEcho;
use Porta\Workbench\Dock\ToolRun;
use Porta\Workbench\Menu\MenuContribution;
use Porta\Workbench\Menu\MenuSurface;
use Tempest\Highlight\TerminalTheme;

/**
 * A plugin's handle into Porta. Registration methods buffer during
 * `activate()` and land atomically when it returns (a throwing plugin
 * contributes nothing). Action methods reach the live IDE through the
 * manager's runtime and are meant for command handlers / event hooks —
 * calling them during `activate()` throws a {@see PluginException}.
 *
 * @phpstan-import-type ResultRow from \Porta\Support\ResultRowShape
 */
final class PluginApi
{
    /**
     * The plugin-API major this Porta speaks. A plugin may pin the major it
     * was written against in its manifest (`extra.porta.api`); a mismatch is
     * declined by name at load, the way an uninstalled dependency is — the
     * alternative is a TypeError deep inside a render pass with nothing
     * pointing at the cause. Bump ONLY on a breaking change to this class's
     * surface; additive growth (new verbs) is not a bump.
     */
    public const int API_VERSION = 1;

    /**
     * The {@see registerInstaller()} "manager" that means *this plugin
     * installs the tool itself* — the candidate's second element is one of the
     * plugin's own command ids, not a package name.
     *
     * Not a package manager, and deliberately not spelled like one: a tool
     * that exists only as a GitHub release has no `composer require` to offer,
     * and before this the one plugin that most needed a Settings button was
     * the only one that could not have one.
     */
    public const string INSTALL_SELF = 'self';

    private const string ID_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/i';
    private const array SETTING_KINDS = ['bool', 'choice', 'text', 'int'];

    /**
     * Plain array, not a {@see PluginBuffer}: paired with the $ids dedup index below.
     *
     * @var list<array{plugin: string, id: string, label: string, handler: \Closure(): void, keys: list<string>}>
     */
    private array $commands = [];
    /** @var array<string, true> registered short ids, for duplicate detection */
    private array $ids = [];
    /**
     * Plain array: the drain transforms rows into {@see PluginMenuSpec}s.
     *
     * @var list<array{id: string, when: (\Closure(): bool)|null, surface: MenuSurface}> command ids and the menu surface each belongs to
     */
    private array $menuItems = [];
    /**
     * Plain array: seven writer methods, positional tuples, drained into the
     * event dispatcher at commit.
     *
     * @var list<array{0: class-string, 1: \Closure(object): void}> [event class, listener]
     */
    private array $hooks = [];

    /** Set when the hooks are drained: registering after that is a mistake, not a no-op. */
    private bool $drained = false;
    /**
     * Plain array: keyed by setting key for dedup, drains into PluginSettings.
     *
     * @var array<string, array{key: string, label: string, kind: string, default: bool|int|string, options: list<string>|null, hint: string|null, min: int|null, max: int|null, step: int|null}> by key
     */
    private array $settingDescriptors = [];
    /** The registration buffers {@see PluginManager::commit()} drains. */
    private readonly PluginContributions $contributions;
    /** @var array<string, true> bare tool ids, for duplicate detection */
    private array $toolIds = [];
    /** @var array<string, true> bare ids, so showList() can reject one this plugin never registered */
    private array $listToolIds = [];
    /** @var array<string, true> ids taken with adoptCommand() — registered BARE, so their menu specs are too */
    private array $adopted = [];

    /** Where this plugin's tools are found — shared with Settings, so the two agree. */
    private readonly ToolLocator $locator;

    /**
     * Built by {@see PluginManager::api()}, which hands over the three things
     * the API asks of it as closures — so the API names no manager, and the
     * manager draining it at commit is the only edge between the two.
     *
     * @param \Closure(): PluginRuntime $runtime the live IDE seams, guarded so an activate()-time call fails as a plugin error
     * @param \Closure(): list<array{plugin: string, id: string, description: string, inputSchema: array<string, mixed>, handler: \Closure(array<string, mixed>, ToolCaller=): (string|array<array-key, mixed>), effect: ?ToolEffect}> $committedTools
     * @param \Closure(PluginObservable): void $emit puts a plugin's event on the bus
     * @param (\Closure(): EditorDisplayOptions)|null $display the editor's display options, for {@see softWrap()}
     * @param list<ToolRequirement> $declaredTools what the plugin's manifest declares under `extra.porta.tools`
     */
    public function __construct(
        private readonly string $pluginName,
        private readonly \Closure $runtime,
        private readonly \Closure $committedTools,
        private readonly \Closure $emit,
        private readonly string $rootDir,
        private readonly PluginSettings $settings,
        private readonly ?TerminalTheme $syntaxTheme = null,
        private readonly ?\Closure $display = null,
        ?ToolLocator $locator = null,
        private readonly array $declaredTools = [],
    ) {
        $this->locator = $locator ?? ToolLocator::forProject($rootDir, $settings);
        $this->contributions = new PluginContributions();
    }

    /**
     * The live syntax color scheme, for a backend that renders its own colours.
     *
     * A **holder**, not a snapshot: the settings layer swaps its inner theme in
     * place, so a backend that asks it per token automatically follows a theme
     * switch with no hook to subscribe to. Safe during activate() — the holder
     * is built before plugins load.
     */
    public function syntaxTheme(): TerminalTheme
    {
        return $this->syntaxTheme ?? new DarkTerminalTheme();
    }

    /**
     * Whether soft wrap is on — the core `softWrap` setting, for a pane that
     * draws long lines of its own (a diff) and should break them as the editor
     * does. Read live, and safe during activate(); pair it with
     * {@see \Porta\Event\SoftWrapChanged} to follow a switch.
     */
    public function softWrap(): bool
    {
        return null !== $this->display && ($this->display)()->softWrap;
    }

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
     *
     * @throws PluginException
     */
    public function registerCommand(string $id, string $label, \Closure $handler, array $keys = []): void
    {
        if (1 !== \preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid command id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        if (\array_key_exists($id, $this->ids)) {
            throw new PluginException(\sprintf('Duplicate command id "%s"', $id));
        }
        $this->ids[$id] = true;
        $this->commands[] = [
            'plugin' => $this->pluginName,
            'id' => $this->pluginName.':'.$id,
            'label' => $label,
            'handler' => $handler,
            'keys' => \array_values($keys),
        ];
    }

    /**
     * Implement a CORE command whose id core has vacated — the way a feature
     * that used to live in `src/` keeps its identity after moving into a
     * bundled plugin.
     *
     * The id stays BARE (`ide_todo`, not `todo:todo`), so an existing
     * `.porta.json` keymap entry still binds it, the Settings → Keymap page
     * still lists it under core's label, and its position in a core menu is
     * unchanged. The label and the default keys come from
     * {@see ActionCatalog} — the identity is core's, only the implementation
     * moved.
     *
     * Only ids core marks `adoptable` may be taken, which is what stops a
     * third-party plugin from quietly becoming `save`.
     *
     * @param \Closure(): void $handler
     *
     * @throws PluginException
     */
    public function adoptCommand(string $id, \Closure $handler): void
    {
        if (!ActionCatalog::isAdoptable($id)) {
            throw new PluginException(\sprintf('Command "%s" is not adoptable — core still implements it (or there is no such action)', $id));
        }
        if (\array_key_exists($id, $this->ids)) {
            throw new PluginException(\sprintf('Duplicate command id "%s"', $id));
        }
        $this->ids[$id] = true;
        $this->adopted[$id] = true;
        $this->commands[] = [
            'plugin' => $this->pluginName,
            'id' => $id,
            'label' => ActionCatalog::label($id),
            'handler' => $handler,
            // None: an adoptable id is an IDE-scope catalog action, so its
            // default key is already in the map ActionCatalog::ideDefaults()
            // builds. Declaring it again here would just shadow itself.
            'keys' => [],
        ];
    }

    /**
     * Show a registered command in the Plugins menu (its label is reused).
     * With $enabledWhen, the item renders disabled while the closure returns
     * false (e.g. the tool's binary is missing) — it is re-evaluated on every
     * menu render, and a throw reads as disabled.
     *
     * $surface picks WHERE it lands: the trailing Plugins menu by default, or
     * the editor's right-click menu ({@see MenuSurface}).
     *
     * @param (\Closure(): bool)|null $enabledWhen
     *
     * @throws PluginException
     */
    public function addMenuItem(string $commandId, ?\Closure $enabledWhen = null, MenuSurface $surface = MenuSurface::Plugins): void
    {
        if (!\array_key_exists($commandId, $this->ids)) {
            throw new PluginException(\sprintf('Unknown command id "%s" for a menu item — register it first', $commandId));
        }
        // An ADOPTED id is registered bare (it is core's), so its menu spec must
        // be bare too — prefixing it would name a command nothing answers to.
        $this->menuItems[] = [
            'id' => \array_key_exists($commandId, $this->adopted) ? $commandId : $this->pluginName.':'.$commandId,
            'when' => $enabledWhen,
            'surface' => $surface,
        ];
    }

    /**
     * Declare a user-editable setting, shown on this plugin's Settings page
     * and persisted in `.porta.json` under `plugins.<name>.<key>`. Call it
     * before {@see setting()} — the descriptor supplies the default and the
     * type coercion. Kinds: 'text', 'bool', 'int', 'choice' (with $options).
     *
     * @param list<string>|null $options required for 'choice'; must contain the default
     * @param ?int              $min     'int' only: the floor the stepper clamps to (default 1)
     * @param ?int              $max     'int' only: the ceiling it clamps to (default unbounded)
     * @param ?int              $step    'int' only: how far one keypress moves it (default 1)
     *
     * @throws PluginException
     */
    public function registerSetting(
        string $key,
        string $label,
        string $kind = 'text',
        bool|int|string $default = '',
        ?array $options = null,
        ?string $hint = null,
        ?int $min = null,
        ?int $max = null,
        ?int $step = null,
    ): void {
        if (1 !== \preg_match(self::ID_PATTERN, $key)) {
            throw new PluginException(\sprintf('Invalid setting key "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $key));
        }
        if (\array_key_exists($key, $this->settingDescriptors)) {
            throw new PluginException(\sprintf('Duplicate setting key "%s"', $key));
        }
        if (!\in_array($kind, self::SETTING_KINDS, strict: true)) {
            throw new PluginException(\sprintf('Unknown setting kind "%s" (expected bool, choice, text or int)', $kind));
        }
        if ('choice' === $kind && (null === $options || [] === $options || !\in_array($default, $options, strict: true))) {
            throw new PluginException(\sprintf('Setting "%s": a choice needs options containing its default', $key));
        }
        // A bound on anything but a number is a mistake worth naming: nothing
        // would clamp it, and the row would advertise a range it does not keep.
        if ('int' !== $kind && (null !== $min || null !== $max || null !== $step)) {
            throw new PluginException(\sprintf('Setting "%s": min/max/step apply to an int, not a %s', $key, $kind));
        }
        if (null !== $min && null !== $max && $min > $max) {
            throw new PluginException(\sprintf('Setting "%s": min %d is above max %d', $key, $min, $max));
        }
        $this->settingDescriptors[$key] = [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'default' => $default,
            'options' => $options,
            'hint' => $hint,
            'min' => $min,
            'max' => $max,
            'step' => $step,
        ];
    }

    /**
     * Provide a language server for $languageId (e.g. 'php'). $resolveCommand
     * returns the launch argv; null when it does not serve the language here;
     * or a {@see ServerNotFound} when it would and its binary is not there —
     * which leaves the language unserved rather than launching a broken
     * process, and names the server as not found on the status bar. The resolver runs
     * lazily whenever the LSP layer (re)configures, so it can read the current
     * `binary` setting; keep it side-effect-free (settings + discovery only).
     * Plugin servers sit below the user's `.porta.json`/`$PORTA_LSP`/`--lsp`
     * config in precedence.
     *
     * When several enabled plugins claim one language, $role decides what
     * happens rather than load order. The first {@see ServerRole::Primary}
     * claim wins and later ones are dropped; a {@see ServerRole::Supplementary}
     * claim runs BESIDE it — it receives the whole document lifecycle and its
     * answers are merged into the mergeable verbs — and is promoted to primary
     * when nothing else claims the language at all. A {@see ServerRole::Relayed}
     * claim is a primary run elsewhere, reading nothing of this project: the
     * one kind a restricted project still starts.
     *
     * $initializationOptions, when given, returns the LSP `initializationOptions`
     * sent in the server's initialize handshake. Like the command resolver it is
     * re-run on every (re)configure — read this plugin's settings in it and a
     * settings edit reaches the relaunched server. An empty array sends nothing.
     *
     * @param \Closure(): (list<string>|ServerNotFound|null) $resolveCommand
     * @param (\Closure(): array<string, mixed>)|null $initializationOptions
     *
     * @throws PluginException
     */
    public function registerLanguageServer(string $languageId, string $name, \Closure $resolveCommand, ?\Closure $initializationOptions = null, ServerRole $role = ServerRole::Primary): void
    {
        if (1 !== \preg_match(self::ID_PATTERN, $languageId)) {
            throw new PluginException(\sprintf('Invalid language id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $languageId));
        }
        $this->contributions->languageServers->add([
            'plugin' => $this->pluginName,
            'languageId' => $languageId,
            'name' => $name,
            'role' => $role,
            'resolve' => $resolveCommand,
            'initOptions' => $initializationOptions,
        ]);
    }

    /**
     * Provide a syntax-highlighting backend for one or more languages (Porta's
     * grammar names — see {@see \Porta\Support\LanguageMap}). `['*']` claims
     * every language. Pass a closure for $languages when the claim list follows
     * this plugin's own settings; it is re-resolved on every query, like
     * {@see registerLanguageServer()}'s command resolver.
     *
     * $highlight receives the whole document text, the language, and a $deliver
     * callback it hands the ANSI-marked result to. A **synchronous** backend
     * calls $deliver before returning. An **asynchronous** one returns
     * immediately and calls $deliver later from the event loop — Porta paints
     * with what it already has and repaints when the result lands. Delivering
     * for a superseded version is a silent no-op, so a backend never has to
     * track whether its answer is still wanted.
     *
     * Two hard rules: it **must not block** (it runs on the way to a render
     * frame — once per document version, not once per frame, but that is still
     * the typing path), and it must **preserve line structure** — same number
     * of `"\n"`-separated lines in as out, identical to the input once ANSI
     * codes are stripped. A throw, or a mangled line count, falls back to plain
     * text rather than rendering a broken buffer; three consecutive faults drop
     * the backend for that buffer.
     *
     * $isAvailable declines the language when e.g. a binary is missing, so it
     * falls through to the next backend instead of failing repeatedly.
     *
     * Precedence: the user's `.porta.json` `highlight` block wins, then the
     * first available claimer in plugin load order, then the built-in engine.
     *
     * @param list<string>|\Closure(): list<string>  $languages
     * @param \Closure(string, string, \Closure(string): void): void $highlight (content, language, deliver)
     * @param (\Closure(): bool)|null                $isAvailable
     * @param string|null                            $id          selection key in config; defaults to the plugin name
     *
     * @throws PluginException
     */
    public function registerHighlighter(
        string $label,
        array|\Closure $languages,
        \Closure $highlight,
        ?\Closure $isAvailable = null,
        ?string $id = null,
    ): void {
        $id ??= $this->pluginName;
        if (1 !== \preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid highlighter id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        if (\is_array($languages) && [] === $languages) {
            throw new PluginException('A highlighter must claim at least one language (or "*")');
        }
        $resolve = $languages instanceof \Closure ? $languages : static fn (): array => \array_values($languages);
        $this->contributions->highlighters->add([
            'plugin' => $this->pluginName,
            'id' => $id,
            'label' => $label,
            'languages' => $resolve,
            'highlight' => $highlight,
            'available' => $isAvailable,
        ]);
    }

    /**
     * Claim file paths for a viewer pane: files matching $claims open in the
     * viewer named by $id instead of the text editor. Today the id names a
     * BUILT-IN pane ('image', 'graph' — the bundled image-viewer/graph-viewer
     * plugins are what makes those viewers enable/disableable); an id no pane
     * answers to falls through to the text path, so a future
     * widget-registration API can widen this without breaking the contract.
     *
     * $claims is a list of file extensions (matched case-insensitively) or a
     * `\Closure(string $path): bool` for richer predicates; a throwing
     * predicate declines the path (reported once).
     *
     * @param list<string>|\Closure(string): bool $claims
     *
     * @throws PluginException
     */
    public function registerViewer(string $id, array|\Closure $claims): void
    {
        $this->contributions->viewers->add($this->route('viewer', $id, $claims));
    }

    /**
     * Claim file paths for a LIVE PREVIEW: files matching $claims open in the
     * text editor as usual, with the pane named by $id beside it showing what
     * the text becomes — and following it as it is edited. The toggles above
     * the editor switch between the text, both, and the preview alone.
     *
     * The pane is whatever {@see registerPane()} registered under $id, and it is
     * fed the document's TEXT as its payload (not what its loader would read
     * from disk), so a pane that previews must take its own source format: the
     * image pane an SVG's markup, the graph pane DOT. An id no pane answers to
     * leaves the file a plain text buffer.
     *
     * A preview claim is asked BEFORE {@see registerViewer()}'s, so a plugin
     * may claim the same files for both: the preview wins while the file is
     * small enough to edit, and the viewer still takes one too large to.
     *
     * @param list<string>|\Closure(string): bool $claims
     *
     * @throws PluginException
     */
    public function registerPreview(string $id, array|\Closure $claims): void
    {
        $this->contributions->previews->add($this->route('preview', $id, $claims));
    }

    /**
     * One routing claim, as both {@see registerViewer()} and
     * {@see registerPreview()} buffer it: the id checked, an extension list
     * turned into the case-insensitive predicate.
     *
     * @param list<string>|\Closure(string): bool $claims
     *
     * @return array{plugin: string, id: string, claims: \Closure(string): bool}
     *
     * @throws PluginException
     */
    private function route(string $kind, string $id, array|\Closure $claims): array
    {
        if (1 !== \preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid %s id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $kind, $id));
        }
        if (\is_array($claims) && [] === $claims) {
            throw new PluginException(\sprintf('A %s must claim at least one file extension', $kind));
        }
        if (\is_array($claims)) {
            /** @var list<string> $list */
            $list = \array_values($claims);
            $extensions = \array_map(\strtolower(...), $list);
            $claims = static fn (string $path): bool => ProjectPaths::hasExtension($path, $extensions);
        }

        return ['plugin' => $this->pluginName, 'id' => $id, 'claims' => $claims];
    }

    /**
     * Register a full-screen view: a workspace takeover with its own sidebar,
     * drag separator and content pane, reached by {@see openView()}.
     *
     * $factory is called ONCE, after plugins have committed, with the
     * {@see \Porta\Workbench\View\ViewServices} core assembles — a plugin
     * class can never be a container service, so this is how a view receives
     * its collaborators. Its region is minted for $id before the call, so the
     * view can fill both panes immediately.
     *
     * Untyped here on purpose, the same reason `registerPane`'s is: naming the
     * return type would put the plugin tier back in front of a widget.
     *
     * @param \Closure $factory \Closure(\Porta\Workbench\View\ViewServices): \Porta\Workbench\View\ContributedView
     *
     * @throws PluginException
     */
    public function registerView(string $id, \Closure $factory): void
    {
        if (1 !== \preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid view id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        $this->contributions->views->add([
            'plugin' => $this->pluginName,
            'id' => $id,
            'factory' => $factory,
        ]);
    }

    /**
     * Supply the PANE a viewer id names: the widget that takes the editor area
     * for files {@see registerViewer()} claimed, and the loader that turns one of
     * those paths into whatever payload the pane understands.
     *
     * The two calls are separate because they answer different questions —
     * "does this file belong to me?" and "what shows it?" — and a plugin may
     * still claim an id some other plugin's pane answers to. An id with a claim
     * and no pane falls through to the text path, unchanged from before panes
     * existed.
     *
     * $pane is called ONCE, at the constructor tail, with the
     * {@see PaneServices} core assembles; a plugin class cannot be a container
     * service, so this is how a pane receives its collaborators. $load runs on
     * every open of a claimed path — a throw declines the file, which is how a
     * pane refuses an oversized or malformed document.
     *
     * @param \Closure $pane  `\Closure(\Porta\Editor\PaneServices): \Porta\Widget\EditorPaneWidget` — untyped here so the
     *                        plugin tier never names a widget class (the `Plugin > Widget` edge round 18 severed)
     * @param \Closure $load  `\Closure(string $path): mixed`
     *
     * @throws PluginException
     */
    public function registerPane(string $id, \Closure $pane, \Closure $load): void
    {
        if (1 !== \preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid pane id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        $this->contributions->panes->add([
            'plugin' => $this->pluginName,
            'id' => $id,
            'pane' => $pane,
            'load' => $load,
        ]);
    }

    /**
     * Declare a LIST TOOL: a bottom-dock tab of results with jump-to-location,
     * the shape core's Problems and Usages tabs have. Registration only
     * declares it — nothing appears until {@see showList()} publishes rows.
     *
     * A row is `['path' => …, 'line' => …, 'text' => …]` with the path
     * project-relative (an absolute one inside the project is relativised) and
     * the line **0-based**, the convention {@see setDiagnostics()} already
     * uses. An optional `ranges` of `['start' => …, 'length' => …]` display
     * columns bolds the match.
     *
     * @param (\Closure(): void)|null            $rerun    F5 on this tab; null leaves the key inert
     * @param (\Closure(string, int): void)|null $activate Enter on a row (path, 0-based line); null takes core's
     *                                                    open-the-file-and-jump, which is what most tools want
     *
     * @throws PluginException
     */
    public function registerListTool(string $id, string $title, ?\Closure $rerun = null, ?\Closure $activate = null): void
    {
        if (1 !== \preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid list tool id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        if (\array_key_exists($id, $this->listToolIds)) {
            throw new PluginException(\sprintf('Duplicate list tool id "%s"', $id));
        }
        if ('' === \trim($title)) {
            throw new PluginException(\sprintf('List tool "%s" needs a title — it is the strip label', $id));
        }
        $this->listToolIds[$id] = true;
        $this->contributions->listTools->add([
            'plugin' => $this->pluginName,
            'id' => $this->pluginName.':'.$id,
            'title' => $title,
            'rerun' => $rerun,
            'activate' => $activate,
        ]);
    }

    /**
     * Provide the DEBUG ADAPTER for a language — the DAP counterpart of
     * {@see registerLanguageServer()}, and it works the same way: the resolver
     * is called when a session starts, so it can read this plugin's `binary`
     * setting and return null while the adapter is not installed yet.
     *
     * Consulted BELOW `.porta.json`'s `languages.<id>.debug`: a user who named
     * an adapter by hand keeps it. Returning the argv is the whole contract —
     * launch configuration stays the user's, because it is theirs to tune.
     *
     * @param \Closure(): (list<string>|null) $resolveCommand
     *
     * @throws PluginException
     */
    public function registerDebugAdapter(string $languageId, \Closure $resolveCommand): void
    {
        if (1 !== \preg_match(self::ID_PATTERN, $languageId)) {
            throw new PluginException(\sprintf('Invalid language id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $languageId));
        }
        $this->contributions->debugAdapters->add(['plugin' => $this->pluginName, 'languageId' => $languageId, 'resolve' => $resolveCommand]);
    }

    /**
     * Offer a TOOL: a verb a program outside the IDE may call — an agent
     * connected through the bundled mcp plugin, today. Core only keeps the
     * list; whatever serves it reads {@see tools()}, so a plugin contributes
     * without depending on, or even knowing about, who calls it.
     *
     * - `$inputSchema` is the JSON Schema of the arguments, an `object` one:
     *   `['type' => 'object', 'properties' => [...], 'required' => [...]]`.
     * - `$handler` receives the decoded arguments, and the {@see ToolCaller}
     *   as a second argument it may leave undeclared, and returns the answer: a
     *   string as it is, an array as JSON. A throw is the caller's error
     *   message, never the IDE's crash.
     * - The handler runs in its OWN fiber, so it may wait: suspend on
     *   `Revolt\EventLoop::getSuspension()` and resume it from a subprocess
     *   callback, and the IDE keeps painting meanwhile.
     *
     * - `$effect` says what calling it does, so the caller's client can run a
     *   {@see ToolEffect::Reads} tool without asking and ask before a
     *   {@see ToolEffect::Writes} one. Leave it out only when it depends.
     *
     * It is called with the user's IDE in whatever state it is in, by
     * something the user started — keep a tool that CHANGES things to what
     * the user could undo.
     *
     * @param array<string, mixed>                                              $inputSchema
     * @param \Closure(array<string, mixed>, ToolCaller=): (string|array<array-key, mixed>) $handler
     *
     * @throws PluginException
     */
    public function registerTool(string $id, string $description, array $inputSchema, \Closure $handler, ?ToolEffect $effect = null): void
    {
        if (1 !== \preg_match(self::ID_PATTERN, $id)) {
            throw new PluginException(\sprintf('Invalid tool id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
        }
        if (\array_key_exists($id, $this->toolIds)) {
            throw new PluginException(\sprintf('Duplicate tool id "%s"', $id));
        }
        if ('' === \trim($description)) {
            throw new PluginException(\sprintf('Tool "%s" needs a description — it is all the caller reads before calling it', $id));
        }
        if ('object' !== ($inputSchema['type'] ?? null)) {
            throw new PluginException(\sprintf('Tool "%s" needs an object input schema (["type" => "object", …])', $id));
        }
        $this->toolIds[$id] = true;
        $this->contributions->tools->add([
            'plugin' => $this->pluginName,
            'id' => $id,
            'description' => $description,
            'inputSchema' => $inputSchema,
            'handler' => $handler,
            'effect' => $effect,
        ]);
    }

    /**
     * Contribute a STATUS-BAR SEGMENT: ambient state, shown at the left of the
     * bar beside the debugger's and before the git branch.
     *
     * Pulled once per RENDER, so the closure must be cheap and must never
     * block — cache whatever it reports and return the cached value. Return
     * null (or '') to show nothing, which is the normal state most of the time.
     * A throwing closure reads as nothing and is reported once.
     *
     * Returning a bare STRING shares one subtle cell with every other plugin
     * doing the same. Returning a {@see \Porta\Workbench\StatusCell} claims a
     * cell of its own, which is what a segment needs when it carries a tone or
     * a right-click menu — the git branch indicator is the case that forced it.
     * The tone is chosen from core's three; a plugin cannot name a colour.
     *
     * @param \Closure(): (string|\Porta\Workbench\StatusCell|null) $segment
     */
    public function registerStatusSegment(\Closure $segment): void
    {
        $this->contributions->statusSegments->add(['plugin' => $this->pluginName, 'segment' => $segment]);
    }

    /**
     * Contribute a TOP-LEVEL MENU — a group of its own in the menu bar, rather
     * than an entry in the trailing Plugins one that {@see addMenuItem()} adds
     * to.
     *
     * $items are command ids spelled `'i:<id>'`, with
     * {@see \Porta\Workbench\Menu\MenuModel::SEP} between groups; an id
     * nothing registered is skipped, which is what lets a menu list a verb that
     * only exists under some condition. $after names the CORE menu title this
     * group follows, so a contributed group lands where it belongs rather than
     * at the end.
     *
     * $viewSafe is the other half and travels with it: while the view named by
     * $viewId owns the main area, only the ids listed here stay enabled. A menu
     * contributed without one greys out its own items the moment its view
     * opens — a bug with no error, which is why the two are one call.
     *
     * @param list<string>            $items    `'i:<command-id>'` entries and separators
     * @param (\Closure(): bool)|null $visible  hides the whole group; null means always shown
     * @param string                  $viewId   the view whose open state $viewSafe applies to, '' for none
     * @param list<string>            $viewSafe command ids that stay enabled while that view is open
     *
     * @throws PluginException
     */
    public function registerMenu(string $title, string $after, array $items, ?\Closure $visible = null, string $viewId = '', array $viewSafe = []): void
    {
        if ('' === $title) {
            throw new PluginException('A contributed menu needs a title');
        }
        $this->contributions->menus->add([
            'plugin' => $this->pluginName,
            'contribution' => new MenuContribution($title, $after, $items, $visible, $viewId, $viewSafe),
        ]);
    }

    /**
     * Tell the status bar this plugin's segment has changed.
     *
     * Segments are PULLED once per render, but the bar caches its assembled
     * line, so a segment that changes between frames — a branch name resolving
     * out of a subprocess — needs to say so or the old text stays until
     * something else invalidates. Cheap and idempotent; call it from whatever
     * callback produced the new value.
     */
    public function refreshStatusSegments(): void
    {
        $runtime = ($this->runtime)();
        $runtime->statusBar->invalidate();
        $runtime->tui->requestRender();
    }

    /**
     * Declare REBINDABLE ACTIONS — keys a pane binds itself, listed on the
     * Settings → Keymap page and overridable in `.porta.json` under their bare
     * ids, exactly like core's.
     *
     * Not commands: a command is globally dispatched and appears in the
     * palette, which is wrong for `-`, `0` and the arrow keys a viewer binds —
     * they must fire only while that pane holds the editor slot. The DEFAULTS
     * live in the pane's own `getDefaultKeybindings()`; what is declared here is
     * the id and the label, which is all the keymap page needs.
     *
     * @param array<string, string> $actions id => untranslated label
     *
     * @throws PluginException
     */
    public function registerActions(array $actions): void
    {
        foreach ($actions as $id => $label) {
            if (1 !== \preg_match(self::ID_PATTERN, $id)) {
                throw new PluginException(\sprintf('Invalid action id "%s" (expected /^[a-z0-9][a-z0-9_-]*$/i)', $id));
            }
            if (\array_key_exists($id, ActionCatalog::ACTIONS)) {
                throw new PluginException(\sprintf('Action "%s" is a core action — pick an id core does not use', $id));
            }
            $this->contributions->actions->add(['plugin' => $this->pluginName, 'id' => $id, 'label' => $label]);
        }
    }

    /**
     * Provide comment/string classification for project search's token-scope
     * filter ("only in comments/strings", "except in ...").
     *
     * Separate from {@see registerHighlighter()} on purpose: this runs once per
     * *matched file* during a search, not on the typing path, and it is always
     * synchronous. $classify returns `[start, end)` byte spans, or null to
     * decline so Porta's built-in classifier serves the language.
     *
     * A backend that shells out should **not** register this — a 500-hit search
     * would mean 500 subprocesses.
     *
     * @param list<string>|\Closure(): list<string>                                       $languages `['*']` claims everything
     * @param \Closure(string, string): (list<array{start: int, end: int}>|null)          $classify  (content, language)
     *
     * @throws PluginException
     */
    public function registerTokenSpans(array|\Closure $languages, \Closure $classify): void
    {
        if (\is_array($languages) && [] === $languages) {
            throw new PluginException('A token-span provider must claim at least one language (or "*")');
        }
        $this->contributions->tokenSpans->add([
            'plugin' => $this->pluginName,
            'languages' => $languages instanceof \Closure ? $languages : static fn (): array => \array_values($languages),
            'classify' => $classify,
        ]);
    }

    /**
     * Declare how to install this plugin's tool binary, plus a predicate for
     * whether it is currently available. When it isn't, the plugin's Settings
     * page offers a one-click install that tries each `[manager, package]`
     * candidate in order, running the first whose manager is on the PATH — e.g.
     * a composer tool passes `[['composer', 'vendor/name']]`; a native tool like
     * phpantom passes `[['brew', 'phpantom-lsp'], ['cargo', 'phpantom_lsp']]`.
     * Supported managers: composer, brew, cargo.
     *
     * A tool that no package manager ships passes {@see self::INSTALL_SELF}
     * with one of the plugin's OWN command ids — `[[PluginApi::INSTALL_SELF,
     * 'install']]` — and the button runs that command instead of a package
     * manager. symfony-lsp is the case: its server exists only as a GitHub
     * release, so the plugin downloads it itself, and without this the one
     * plugin that most needs a Settings button was the one plugin that could
     * not have one. Such a row does NOT disappear once the tool is there: a
     * self-installer is also its own updater, so the button re-reads
     * "Update".
     *
     * For a tool with no installable source at all, don't call this — no
     * install is offered.
     *
     * @param \Closure(): bool                   $isAvailable
     * @param list<array{0: string, 1: string}> $via ordered [manager, package] candidates
     * @param (\Closure(): string)|null          $describe what is installed right now, in a few
     *        words (a version, say), shown beside the button. Only read while $isAvailable holds;
     *        '' shows nothing. It is pulled on every settings render, so it must be cheap
     *
     * @throws PluginException
     */
    public function registerInstaller(string $tool, \Closure $isAvailable, array $via, ?\Closure $describe = null): void
    {
        if ('' === \trim($tool)) {
            throw new PluginException('An installer needs a non-empty tool name');
        }
        if ([] === $via) {
            throw new PluginException('An installer needs at least one [manager, package] candidate');
        }
        foreach ($via as [$manager, $package]) {
            $known = ToolManager::tryFrom($manager);
            if (null === $known || ToolManager::Github === $known) {
                throw new PluginException(\sprintf(
                    'Unknown install manager "%s" (expected composer, brew, brew-cask, cargo, npm or %s; a GitHub release is declared in the manifest)',
                    $manager,
                    self::INSTALL_SELF,
                ));
            }
            if ('' === \trim($package)) {
                throw new PluginException('An installer candidate needs a non-empty package name');
            }
            // Checked here rather than at press time: a typo would otherwise
            // ship a button that silently does nothing (CommandRegistry::run()
            // is a no-op for an unknown id), and the plugin that owns the
            // command is the one that can be told.
            if (self::INSTALL_SELF === $manager && !\array_key_exists($package, $this->ids)) {
                throw new PluginException(\sprintf('Self-install names "%s", which is not a command this plugin registered', $package));
            }
        }
        $this->contributions->installers->add([
            'plugin' => $this->pluginName,
            'tool' => $tool,
            'available' => $isAvailable,
            'via' => $via,
            'describe' => $describe,
        ]);
    }

    /**
     * The current value of a setting this plugin registered — the user's
     * `.porta.json`/Settings-page value coerced to the declared kind, or the
     * default. Safe during activate() (the config loads before plugins), but
     * {@see registerSetting()} must have run first.
     *
     * @throws PluginException
     */
    public function setting(string $key): bool|int|string
    {
        $descriptor = $this->settingDescriptors[$key] ?? throw new PluginException(\sprintf('Unknown setting "%s" — registerSetting() it first', $key));
        $raw = $this->settings->raw($this->pluginName, $key);
        if (null === $raw) {
            return $descriptor['default'];
        }

        return SettingsRowKind::from($descriptor['kind'])->coerce($raw, $descriptor['default'], $descriptor['options'] ?? []);
    }

    /**
     * Where the tool this plugin's manifest declares as $name
     * (`extra.porta.tools`) is — its setting, `vendor/bin` (composer tools,
     * in a trusted project), the PATH, then `~/.porta/bin` — or null when it
     * is not installed. The same answer the Settings page shows, so a menu
     * entry this greys and a Tools row that says "missing" cannot disagree.
     *
     * An undeclared $name is looked for as that binary, read from the
     * $setting given and searched per $lookup — the shape a tool built in code
     * has. Not cached: the setting can change at runtime. Safe during
     * activate().
     */
    public function toolBinary(string $name, ?string $setting = null, ?BinarySearch $lookup = null): ?string
    {
        $declared = \array_find($this->declaredTools, static fn (ToolRequirement $tool): bool => $name === $tool->name);

        return $this->locator->locate($declared ?? new ToolRequirement($this->pluginName, $name, $name, setting: $setting, lookup: $lookup));
    }

    /**
     * Run $listener once the IDE is up — layout applied, services wired, no
     * buffer opened yet, so setup lands before the first `onFileOpened` rather
     * than racing it. It receives the project root, matching the other hooks'
     * signature; a closure taking no argument is fine.
     *
     * @param \Closure(string): void $listener
     *
     * @throws PluginException
     */
    public function onReady(\Closure $listener): void
    {
        $this->listen(IdeReady::class, static fn (IdeReady $event) => $listener($event->projectRoot));
    }

    /**
     * Run $listener as the IDE shuts down, before core tears its services down.
     *
     * The event loop has ALREADY stopped by then, so the listener must finish
     * synchronously — a timer or subprocess it starts will never run.
     * Persisting something is what this is for.
     *
     * @param \Closure(string): void $listener
     *
     * @throws PluginException
     */
    public function onShutdown(\Closure $listener): void
    {
        $this->listen(IdeShuttingDown::class, static fn (IdeShuttingDown $event) => $listener($event->projectRoot));
    }

    /**
     * @param \Closure(string): void $listener receives the absolute path
     *
     * @throws PluginException
     */
    public function onFileOpened(\Closure $listener): void
    {
        $this->listen(BufferOpened::class, static fn (BufferOpened $event) => $listener($event->buffer->path));
    }

    /**
     * @param \Closure(string): void $listener receives the absolute path
     *
     * @throws PluginException
     */
    public function onFileSaved(\Closure $listener): void
    {
        $this->listen(BufferSaved::class, static fn (BufferSaved $event) => $listener($event->buffer->path));
    }

    /**
     * A refactoring rewrote a file that had no open buffer.
     *
     * The listener receives the path and **the text the write replaced** — the
     * new content is on disk and needs no announcement, while the old exists
     * nowhere else once the write returns. This is not a save and no
     * {@see onFileSaved} fires for it: a save is something the user did, to a
     * buffer that has its own undo stack.
     *
     * @param \Closure(string, string): void $listener receives the absolute path and the previous text
     *
     * @throws PluginException
     */
    public function onFileRewritten(\Closure $listener): void
    {
        $this->listen(FileRewritten::class, static fn (FileRewritten $event) => $listener($event->path, $event->previousText));
    }

    /**
     * The poller replaced a clean buffer with what the file now holds on disk.
     *
     * Sugar over the internal {@see BufferReloaded}, which carries a mutable
     * `Buffer` and so cannot be observable — the adapter hands the path only,
     * exactly as {@see onFileOpened} and {@see onFileSaved} do. A producer of
     * file-scoped state (change marks, coverage) wants this: the content moved
     * without a save.
     *
     * @param \Closure(string): void $listener receives the absolute path
     *
     * @throws PluginException
     */
    public function onFileReloaded(\Closure $listener): void
    {
        $this->listen(BufferReloaded::class, static fn (BufferReloaded $event) => $listener($event->buffer->path));
    }

    /**
     * The active file changed — not every SHOW of it.
     *
     * Listens to the deduped {@see ActiveBufferChanged}, so re-showing the
     * buffer that is already active (config re-apply, pane swaps, focus churn)
     * stays silent while core listeners still see every show.
     *
     * @param \Closure(string): void $listener receives the absolute path
     *
     * @throws PluginException
     */
    public function onBufferSwitched(\Closure $listener): void
    {
        $this->listen(ActiveBufferChanged::class, static fn (ActiveBufferChanged $event) => $listener($event->path));
    }

    /**
     * One of THIS plugin's settings changed — the Settings page wrote it, and
     * whatever the plugin cached from it is now stale.
     *
     * Only its own: a plugin never sees another's configuration. Runs after the
     * new value is persisted, so `setting()` inside the listener reads the new
     * one; the value is handed over anyway, because the common shape is a
     * `match` on the key.
     *
     * Without this, a setting a plugin reads once at `activate()` is
     * restart-required by construction — which is right for a few (a binary
     * path baked into a process runner) and wrong for the rest.
     *
     * @param \Closure(string, bool|int|string): void $listener receives the key and its new value
     */
    public function onSettingChanged(\Closure $listener): void
    {
        $this->contributions->settingHooks->add(['plugin' => $this->pluginName, 'listener' => $listener]);
    }

    /**
     * The theme changed; the listener receives the new theme name.
     *
     * For a highlighting backend that bakes colours at construction rather than
     * reading {@see syntaxTheme()} per token — drop the cached engine here and
     * rebuild it lazily. Runs before core drops the highlight caches, so the
     * next paint uses the rebuilt engine.
     *
     * @param \Closure(string): void $listener
     *
     * @throws PluginException
     */
    public function onThemeChanged(\Closure $listener): void
    {
        $this->listen(ThemeChanged::class, static fn (ThemeChanged $event) => $listener($event->theme->value));
    }

    /**
     * Listen to a core event directly.
     *
     * The six named hooks above are sugar over this one; `on()` reaches the
     * rest of the observable set, and hands the listener the event OBJECT
     * rather than a string. The class must implement
     * {@see \Porta\Event\PluginObservable} — the frozen, documented subset of
     * `Porta\Event`; everything else there is internal and changes without
     * notice, which is why naming one is refused rather than merely useless.
     *
     * Runs at {@see \Porta\Plugin\Runtime\PluginManager::PLUGIN_PRIORITY},
     * after every priority-0 core listener. A throw is caught and flashed like
     * any other plugin closure. There is no `off()`: disabling the plugin
     * removes its listeners.
     *
     * @param class-string                  $eventClass a {@see PluginObservable} implementation
     * @param \Closure(PluginObservable): void $listener
     *
     * @throws PluginException
     */
    public function on(string $eventClass, \Closure $listener): void
    {
        if (!\is_a($eventClass, PluginObservable::class, allow_string: true)) {
            throw new PluginException(\sprintf('"%s" is not a plugin-observable event — see the event list in docs/plugins.md', $eventClass));
        }
        $this->listen($eventClass, $listener);
    }

    /**
     * The unchecked door the named hooks use: core code naming core event
     * classes, which is how `onFileOpened`/`onFileSaved` keep working while
     * {@see BufferOpened}/{@see BufferSaved} stay internal — they carry a
     * mutable `Buffer`, so they are not something a plugin may hold.
     *
     * Refuses once the buffer has been drained. Registrations are collected
     * during `activate()` and committed atomically; one arriving after that
     * used to append to an array nobody would read again, so a listener
     * subscribed from a command handler simply never fired. A silent no-op is
     * the wrong answer to a plugin author's mistake, and `on()` widens the door
     * it comes through.
     *
     * @param class-string           $eventClass
     * @param \Closure(never): void  $listener
     */
    private function listen(string $eventClass, \Closure $listener): void
    {
        if ($this->drained) {
            throw new PluginException(\sprintf('Listeners must be registered during activate(); the one for "%s" arrived after this plugin was committed', $eventClass));
        }
        /** @var \Closure(object): void $listener */
        $this->hooks[] = [$eventClass, $listener];
    }

    /**
     * Render fenced Markdown asynchronously. The returned closure cancels the
     * request. Success carries PNG bytes or terminal rows with relative origins.
     *
     * @param non-empty-list<string> $languages
     * @param \Closure(string, string, string, int, \Closure(\Porta\Help\Fence\FenceResult): void, \Closure(string): void): \Closure(): void $render
     */
    public function registerMarkdownFence(array $languages, \Closure $render): void
    {
        if ($this->drained) {
            throw new PluginException('Markdown fences must be registered during activate()');
        }
        if ([] === $languages) {
            throw new PluginException('Markdown fence languages must not be empty');
        }
        foreach ($languages as $language) {
            if (1 !== \preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $language)) {
                throw new PluginException('Invalid Markdown fence language: '.$language);
            }
        }
        $this->contributions->markdownFences->add([
            'plugin' => $this->pluginName,
            'languages' => \array_values(\array_unique(\array_map(\strtolower(...), $languages))),
            'render' => $render,
        ]);
    }

    // --- Actions (live IDE; PluginException during activate()) ---------------

    /**
     * Open (or reuse) this tool's dock tab, show $rows and focus it.
     *
     * @param list<ResultRow> $rows
     * @param ToolRun                                                                                          $run     whether more rows may follow (the tab shows 'searching…')
     */
    public function showList(string $id, array $rows = [], ToolRun $run = ToolRun::Finished): void
    {
        $runtime = ($this->runtime)(); // activate()-phase gate
        $runtime->dockTools->open($this->listToolId($id), $rows, $run);
    }

    /**
     * Replace this tool's rows in place — no open, no focus steal, the user's
     * selection and collapse preserved. A silent no-op once the user has ✖'d
     * the tab, which is why an async tool publishes by id and never holds one.
     *
     * @param list<ResultRow> $rows
     */
    public function updateList(string $id, array $rows, ToolRun $run = ToolRun::Finished): void
    {
        $runtime = ($this->runtime)();
        $runtime->dockTools->update($this->listToolId($id), $rows, $run);
    }

    /** This plugin's namespaced tool id, or a refusal naming the missing registration. */
    private function listToolId(string $id): string
    {
        if (!\array_key_exists($id, $this->listToolIds)) {
            throw new PluginException(\sprintf('Unknown list tool "%s" — registerListTool() it first', $id));
        }

        return $this->pluginName.':'.$id;
    }

    /**
     * Ask the user to pick one of $items — the centered list overlay the IDE's
     * own pickers use. `$onPick` receives the chosen item's value; dismissing
     * the overlay calls nothing.
     *
     * The one thing a plugin could not do before this: `flash()` tells, and
     * nothing asked.
     *
     * A value => label map is the short spelling. Pass full rows —
     * `['label' => …, 'hint' => …, 'value' => …]` — when the list needs a
     * dimmed second column: a stash's date, a branch's tracking state. Both
     * spellings reach the same overlay.
     *
     * What it answers withdraws the question: the list closes, when it is
     * still the one open — say, when whoever the question was about went
     * away before anyone answered. Ignore it otherwise.
     *
     * @param array<string, string>|list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}> $items in the order to show them
     * @param \Closure(string): void                                                        $onPick
     *
     * @return \Closure(): void withdraws the list
     */
    public function pick(string $title, array $items, \Closure $onPick): \Closure
    {
        $runtime = ($this->runtime)(); // activate()-phase gate
        $rows = [];
        foreach ($items as $value => $label) {
            $rows[] = \is_array($label) ? $label : ['label' => $label, 'hint' => '', 'value' => (string) $value];
        }
        $runtime->modals->openListOverlay($title, $rows, $onPick);

        return static fn () => $runtime->modals->withdrawListOverlay($onPick);
    }

    /**
     * Ask the user for one line of text, pre-filled with $initial. `$onSubmit`
     * receives what they typed; cancelling calls nothing. `TextEcho::Masked`
     * draws a bullet per character — a password, which is never shown.
     *
     * @param \Closure(string): void $onSubmit
     */
    public function prompt(string $label, string $initial, \Closure $onSubmit, TextEcho $echo = TextEcho::Visible): void
    {
        $runtime = ($this->runtime)();
        $runtime->modals->openPrompt($label, $initial, $onSubmit, echo: $echo);
    }

    /**
     * Show a transient message. A string is shown exactly as written; pass a
     * {@see Markup} to style it — `Markup::of('Pushed <info>main</>')`, the
     * tags of Symfony Console's formatter, resolved in the live theme. Escape
     * what you splice in with {@see \Porta\Ui\Markup\MarkupParser::escape()}.
     */
    public function flash(string|Markup $message, float $seconds = 2.5): void
    {
        $runtime = ($this->runtime)();
        $runtime->notifier->flash($message, $seconds);
    }

    /**
     * Say it with a SEVERITY — the same toast {@see flash()} shows, coloured
     * for what happened: `Success` for a completed action, `Error` for one that
     * failed, `Warn` for one that half-worked.
     *
     * The severity is chosen from core's four and core owns what each looks
     * like, the same split {@see registerStatusSegment()} draws between asking
     * for prominence and naming a colour. `flash()` is this at `Info`.
     *
     * A `$slot` makes the message replaceable: a later notify in the same slot
     * takes over the card still showing instead of stacking beside it — show
     * "Fetching…" for as long as it takes, then its outcome in the same place.
     *
     * $message reads as in {@see flash()}: literal as a string, styled as a Markup.
     */
    public function notify(string|Markup $message, ToastSeverity $severity = ToastSeverity::Info, float $seconds = 2.5, ?string $slot = null): void
    {
        ($this->runtime)()->notifier->notify($message, $severity, $seconds, $slot);
    }

    /**
     * Persist a new value for a setting this plugin registered — coerced to the
     * declared kind, kept live and written to `.porta.json` under
     * `plugins.<name>.<key>`, exactly like a Settings-page edit: this plugin's
     * own {@see onSettingChanged()} hooks fire, and when it provides a
     * language server the LSP re-resolves and relaunches live. An action
     * method: calling it during activate() throws (seed defaults via
     * {@see registerSetting()} instead).
     *
     * @throws PluginException
     */
    public function changeSetting(string $key, bool|int|string $value): void
    {
        if (!\array_key_exists($key, $this->settingDescriptors)) {
            throw new PluginException(\sprintf('Unknown setting "%s" — registerSetting() it first', $key));
        }
        $runtime = ($this->runtime)(); // activate()-phase gate
        $this->settings->change($this->pluginName, $key, $value);
        // The hook gets the COERCED value — what setting() reads back now.
        $runtime->lifecycle->onPluginSettingChanged($this->pluginName, $key, $this->settings->value($this->pluginName, $key));
        $runtime->tui->requestRender();
    }

    /**
     * Tell the IDE this plugin just replaced its language server's BINARY on
     * disk, so the servers are re-resolved and the running sessions relaunched.
     *
     * For a plugin that installs its own server. {@see changeSetting()} is not
     * a substitute: the LSP pool skips a reconfigure whose resolved map is
     * unchanged — deliberately, or every setting toggle would tear down every
     * server — and an UPGRADE resolves to byte-identical argv, so the old
     * process keeps answering while the install reports success.
     *
     * An action method: calling it during activate() throws.
     *
     * @throws PluginException
     */
    public function relaunchLanguageServer(): void
    {
        $runtime = ($this->runtime)(); // activate()-phase gate
        $runtime->lifecycle->onServerBinaryReplaced($this->pluginName);
        $runtime->tui->requestRender();
    }

    /** The active TAB's file path (viewer tabs included), or null when no tab is open. */
    public function activeFile(): ?string
    {
        // The active TAB's file — buffer truth, not the editor widget's (a
        // viewer tab neutralizes the editor document, but its file is still
        // the active one; the graph-viewer's export glue needs exactly that).
        $path = ($this->runtime)()->buffers->active()?->path;

        return null !== $path && '' !== $path ? $path : null;
    }

    /**
     * The file this invocation is ABOUT: the right-clicked path when the
     * command came from a file context menu (a tree row, a tab), else
     * {@see activeFile()}.
     *
     * The fallback is what makes this the right default for a file verb: the
     * same command answers a right-click on `Other.php`, a keystroke, and a
     * palette entry, without the plugin knowing which one it was.
     */
    public function contextFile(): ?string
    {
        return ($this->runtime)()->menuFile->file() ?? $this->activeFile();
    }

    /**
     * The files selected in the project tree, in the order the tree shows them.
     *
     * One file is the ordinary case — the tree always has its cursor row
     * selected — and several mean the user built a set deliberately, with
     * Ctrl+Click, Shift+Click or Shift+arrows. A command that acts on a pair
     * (comparing two files, say) reads this and says so when the count is
     * wrong; directories are never in it.
     *
     * @return list<string>
     */
    public function selectedFiles(): array
    {
        return ($this->runtime)()->tree->selectedPaths();
    }

    /** The active text document's full contents, or null when none is active. */
    public function documentText(): ?string
    {
        $runtime = ($this->runtime)();

        return null !== $this->activeFile() ? $runtime->editor->getDocument()->getText() : null;
    }

    /**
     * The caret position in the active text file (0-based line and byte
     * column), and the first line the editor shows of it — where the user is
     * looking, which the caret alone does not say. Under soft wrap the top
     * line can be scrolled partway: `segment` is how many of its rows are
     * above the view (0 otherwise).
     *
     * @return array{line: int, column: int, top: int, segment: int, sticky: list<int>}|null
     */
    public function caretPosition(): ?array
    {
        $runtime = ($this->runtime)();
        if (null === $this->activeFile()) {
            return null;
        }
        $document = $runtime->editor->getDocument();

        $viewport = $runtime->editor->viewport();

        return ['line' => $document->cursorLine, 'column' => $document->cursorCol, 'top' => $viewport->vScroll, 'segment' => $viewport->vScrollSegment, 'sticky' => $viewport->stickyLines];
    }

    /**
     * What the editor showed on each of its first $rows screen rows at its
     * last paint, for a pane drawn level with it, row for row: the lines
     * sticky scroll pins at the top, then the lines from the one scrolled
     * to, folded ones skipped, each taking a row per code lens above it, a
     * row per wrapped segment of its text, and a row per line block under
     * it. Each row names its 0-based `line` (null past the last), the
     * `segment` of the line's text it shows (null on a lens or block row)
     * and how many segments the text takes (`of`). Empty with no text file
     * active. An action method: it throws during `activate()`.
     *
     * @return list<array{line: ?int, segment: ?int, of: int}>
     */
    public function editorScreen(int $rows): array
    {
        if (null === $this->activeFile()) {
            return [];
        }
        $editor = ($this->runtime)()->editor;
        $sticky = \array_slice($editor->viewport()->stickyLines, offset: 0, length: \max(0, $rows));
        $screen = \array_map(static fn (int $line): array => ['line' => $line, 'segment' => 0, 'of' => 1], $sticky);
        foreach ($editor->screenRows($rows - \count($sticky)) as $row) {
            $screen[] = null === $row ? ['line' => null, 'segment' => null, 'of' => 1] : ['line' => $row->line, 'segment' => $row->segment(), 'of' => $row->segments];
        }

        return $screen;
    }

    /**
     * The open buffers as a plugin may work with them: which files are open
     * and edited, their text as it is on screen, editing and saving one — see
     * {@see OpenBuffers}. An action method: it throws during `activate()`.
     */
    public function openBuffers(): OpenBuffers
    {
        $runtime = ($this->runtime)();

        return new OpenBuffers($runtime->buffers, $runtime->saver, $runtime->documents, $runtime->files, $runtime->workspaceEditor);
    }

    /**
     * The people this plugin brought to the project, in core's roster beside
     * the other Porta instances on the same files — and the user's own name,
     * as the others see it. See {@see PluginParticipants}. An action method:
     * it throws during `activate()`.
     */
    public function participants(): PluginParticipants
    {
        return new PluginParticipants($this->pluginName, ($this->runtime)()->participants);
    }

    /**
     * The Run menu's tasks and the terminal sessions they run in, for a
     * plugin that starts one and reads what it printed — see {@see Runs}. An
     * action method: it throws during `activate()`.
     */
    public function runs(): Runs
    {
        return ($this->runtime)()->runs;
    }

    /**
     * What the IDE knows about the CODE, from its language servers and
     * analysers: the diagnostics on the open buffers, and — asked of the
     * server for a file, open or not — where a symbol is declared and used,
     * what it is, and which symbols the project has by name. See
     * {@see CodeIntelligence}.
     *
     * An action method: it throws during `activate()`.
     */
    public function codeIntelligence(): CodeIntelligence
    {
        $runtime = ($this->runtime)();

        return new CodeIntelligence($runtime->lspQuery, $runtime->buffers, $runtime->workspaceEditor, $runtime->documents);
    }

    /**
     * Every tool every running plugin registered with {@see registerTool()},
     * in load order — the read a plugin that SERVES tools needs. Read it when
     * it is needed rather than once: {@see \Porta\Event\ToolsChanged} fires
     * when the set moves under a live enable or disable.
     *
     * An action method: during `activate()` the set is still half-built, so it
     * throws there.
     *
     * @return list<array{plugin: string, id: string, description: string, inputSchema: array<string, mixed>, handler: \Closure(array<string, mixed>, ToolCaller=): (string|array<array-key, mixed>), effect: ?ToolEffect}>
     */
    public function tools(): array
    {
        ($this->runtime)(); // the not-during-activate() gate

        return ($this->committedTools)();
    }

    /**
     * Replace the active text buffer's WHOLE content — the verb behind "apply
     * what my tool produced": a formatter's output, a restored snapshot.
     *
     * - **One** undo step: Ctrl+Z puts back what was there, caret and all.
     * - The caret stays where it is, clamped into the new text — a restore does
     *   not dump the user at the end of the document.
     * - A full LSP didChange goes out, as if the text had been typed.
     * - A silent no-op when nothing is open, when the active tab is a VIEWER
     *   pane, or when the text is IDENTICAL: a tool that changed nothing must
     *   not look like it did.
     *
     * It never touches the file on disk. Save stays the user's verb — which is
     * also why an async tool must check `activeFile()` before applying: the
     * buffer the user is looking at may not be the one your subprocess read.
     */
    public function replaceDocument(string $text): void
    {
        $runtime = ($this->runtime)(); // activate()-phase gate
        if ($runtime->documents->replaceActive($text)) {
            $runtime->tui->requestRender();
        }
    }

    /**
     * Translate a message through the IDE's catalogs — the same lookup core's
     * own strings take, so a bundled feature that moves into a plugin keeps its
     * translations instead of becoming English in every other locale.
     *
     * The message id IS the English source string. A plugin's own strings
     * come from the `translations/` dir beside its plugin.php, which the
     * loader hands the translator once the plugin commits; a string no
     * catalog knows passes through unchanged.
     *
     * @param array<string, string|int> $params placeholders, e.g. `['%name%' => $file]`
     */
    public function trans(string $id, array $params = []): string
    {
        return ($this->runtime)()->translator->trans($id, $params);
    }

    /** Insert text at the caret (undoable, LSP-synced). No-op without a text file. */
    public function insertAtCaret(string $text): void
    {
        $runtime = ($this->runtime)();
        if (null === $this->activeFile()) {
            return;
        }
        $runtime->editor->insertText($text);
        $runtime->tui->requestRender();
    }

    /** Open a file (absolute path) and focus the editor. */
    public function openFile(string $path): void
    {
        ($this->runtime)()->files->openFile($path, focus: OpenFocus::Editor);
    }

    /**
     * Reopen the IDE on the project at $path — a git worktree, say. It is
     * the File › Open Project switch: a refused path flashes and nothing
     * happens, unsaved buffers hold it for a repeat, and an untrusted project
     * is asked about. Then this session ends and the new one boots.
     *
     * $repeat is the clause the unsaved-changes refusal ends on, already
     * translated: what the user does again to go through with it.
     */
    public function openProject(string $path, string $repeat): void
    {
        ($this->runtime)()->projects->openPath($path, $repeat);
    }

    /**
     * Scroll the editor's view by $rows screen rows, the caret where it is,
     * as the wheel over the editor does: under soft wrap a tall line a row at
     * a time, a code lens or a line block a row like any other. The view
     * snaps back to the caret the next time the caret moves. An action
     * method: it throws during `activate()`.
     */
    public function scrollEditor(int $rows): void
    {
        $runtime = ($this->runtime)();
        $runtime->editor->scrollRows($rows);
        $runtime->tui->requestRender();
    }

    /**
     * Move the caret (0-based line, UTF-16 column) in the active file and
     * scroll it into view — or, given $top, scroll so the editor shows that
     * line first: where someone else is looking, say. The view snaps back to
     * the caret the next time the caret moves, as after a wheel scroll.
     */
    public function jumpTo(int $line, int $column = 0, ?int $top = null): void
    {
        $runtime = ($this->runtime)();
        $runtime->editor->jumpTo($line, $column);
        if (null !== $top) {
            $runtime->editor->scrollBy($top - $runtime->editor->viewport()->vScroll);
        }
        $runtime->tui->requestRender();
    }

    /**
     * Publish diagnostics for an open file — they render exactly like LSP
     * diagnostics (inline messages, gutter/stripe colors, the Problems panel)
     * and replace any previous set THIS PLUGIN published for that file, so an
     * empty list clears them — another plugin's findings, and a language
     * server's, are untouched. A path with no open buffer is a silent no-op.
     *
     * @param list<array{line: int, message: string, severity?: int, column?: int, code?: string}> $diagnostics 0-based line; severity is the LSP scale, 1 = error (default), 2 = warning, 3 = information, 4 = hint; `code` names the rule that fired
     */
    public function setDiagnostics(string $path, array $diagnostics): void
    {
        $runtime = ($this->runtime)();
        $byLine = [];
        foreach ($diagnostics as $diagnostic) {
            $column = \max(0, $diagnostic['column'] ?? 0);
            $row = [
                'severity' => DiagnosticSeverity::fromWire($diagnostic['severity'] ?? null),
                'message' => $diagnostic['message'],
                'col' => $column,
                'endCol' => $column,
            ];
            // The rule that fired, when the tool names one. Its own field
            // rather than glued onto the message: the Problems panel shows it
            // at the END of the row, and a plugin that prefixed it by hand put
            // it in front of the sentence the reader wants.
            $code = $diagnostic['code'] ?? '';
            if ('' !== $code) {
                $row['code'] = $code;
            }
            $byLine[\max(0, $diagnostic['line'])][] = $row;
        }
        // Owned by the plugin, so a save-time analyser and a keystroke-time
        // language server stop wiping each other's findings for the file.
        $runtime->diagnostics->applyDiagnostics(Uri::fromPath($path), $byLine, $this->pluginName);
        $runtime->tui->requestRender();
    }

    /**
     * Put an event on Porta's bus.
     *
     * The symmetric half of {@see on()}: what a plugin may observe, it may
     * also announce. The parameter is an INSTANCE rather than a class-string,
     * so the {@see PluginObservable} marker is enforced by the type system and
     * needs no runtime check — and the plugin never holds the dispatcher,
     * which is what stops it reaching the internal events.
     *
     * Core listeners run first, at priority 0; other plugins hear it after.
     * An action method, so it throws during `activate()` — nothing has been
     * wired yet, and an event dispatched into a half-built IDE is a bug
     * wearing a notification.
     */
    public function emit(PluginObservable $event): void
    {
        ($this->runtime)(); // the same not-during-activate() gate the other actions carry
        ($this->emit)($event);
    }

    /**
     * Open a registered full-screen view, or close it. Every other view yields
     * first — one owns the workspace at a time.
     *
     * A view's own commands reach it through here rather than by holding the
     * controller: the registry hands back a `ContributedView` without a view's
     * own verbs on it, deliberately, so that a capability only one view has
     * stays that view's business.
     */
    public function openView(string $id): void
    {
        ($this->runtime)()->views->open($id);
    }

    public function closeView(string $id): void
    {
        ($this->runtime)()->views->close($id);
    }

    /**
     * Flip a registered view open or closed — what a keybinding on a view
     * wants, and the reason it is here rather than composed from the two above.
     *
     * "Is it open?" is a question only the registry can answer, and a plugin
     * asking it any other way has to hold its own controller for the duration
     * — which means keeping a reference alive purely to read one bool, against
     * an object built later than everything that needs it.
     */
    public function toggleView(string $id): void
    {
        ($this->runtime)()->views->toggle($id);
    }

    /**
     * Put text on the system clipboard (OSC 52 where the terminal allows it).
     *
     * The one verb a full-screen view needed that had no plugin route at all:
     * `Support\Clipboard` is a single-method service, and a view that copies a
     * commit hash cannot inject it.
     */
    public function copyToClipboard(string $text): void
    {
        ($this->runtime)()->clipboard->copy($text);
    }

    /**
     * Publish version-control change marks for a file — the gutter's
     * added/modified/deleted stripe. Keys are 0-based lines, values one of
     * `added`, `modified`, `deleted`; an empty array clears the file.
     *
     * Path-keyed, unlike {@see setLineAnnotations}: the answer arrives from a
     * subprocess and the user may have switched tabs, so name the file you
     * computed and core decides whether it is on screen. A path with no open
     * buffer is a silent no-op.
     *
     * The three kinds are core's vocabulary. A plugin says which lines changed
     * and how; core owns what that looks like, which is why a plugin cannot
     * invent a colour here.
     *
     * @param array<int, string> $marks 0-based line => 'added'|'modified'|'deleted'
     */
    public function setChangeMarks(string $path, array $marks): void
    {
        $runtime = ($this->runtime)();
        $known = [];
        foreach ($marks as $line => $kind) {
            // Normalised at the boundary, like setDiagnostics' severity: an
            // unknown kind has no Role behind it, so dropping it here is what
            // stops it reaching the renderer as a colour nobody chose.
            $parsed = ChangeMarkKind::tryFrom($kind);
            if (null === $parsed) {
                continue;
            }

            $known[\max(0, $line)] = $parsed;
        }
        $runtime->changeMarks->applyChangeMarks($path, $known);
    }

    /**
     * Publish the working tree's per-FILE version-control status — what the file
     * tree marks its rows with. Keys are absolute paths, values one of `added`,
     * `modified`, `deleted`.
     *
     * The map REPLACES whole, unlike {@see setChangeMarks}' per-file writes: a
     * file dropping out of it is precisely the news that it went back to
     * unmodified, and there is no other way to say that.
     *
     * Same boundary rule as change marks — the three kinds are core's
     * vocabulary, so a plugin says which files changed and how, and core owns
     * what that looks like. An unknown kind is dropped rather than rendered.
     *
     * @param array<string, string> $statuses absolute path => 'added'|'modified'|'deleted'
     */
    public function setFileStatuses(array $statuses): void
    {
        $runtime = ($this->runtime)();
        $known = [];
        foreach ($statuses as $path => $kind) {
            $parsed = ChangeMarkKind::tryFrom($kind);
            if ('' === $path || null === $parsed) {
                continue;
            }
            $known[$path] = $parsed;
        }
        $runtime->fileStatuses->applyFileStatuses($known);
    }

    /**
     * Show or hide the version-control gutter STRIP — the one-column stripe
     * {@see setChangeMarks()} paints into.
     *
     * Separate from publishing marks, because they answer different questions:
     * "which lines changed?" is the plugin's, "is there a column for them?" is
     * the user's. Publishing nothing while the strip is on leaves an empty
     * column indented into every file, which is what the setting behind this
     * exists to remove.
     *
     * Hiding drops whatever marks were showing, so nothing lingers under a
     * strip that is no longer painted.
     */
    public function showChangeMarkGutter(bool $on): void
    {
        $runtime = ($this->runtime)();
        $runtime->editorView->setVcsGutter($on);
        $runtime->tui->requestRender();
    }

    /**
     * Mark the lines of a file that a conversation is attached to — a review
     * comment thread. Each gets a glyph in the gutter's marker column and a
     * mark on the scrollbar stripe, and the pointer over the glyph reads the
     * summary in the status bar.
     *
     * Path-keyed and plugin-scoped like {@see setDiagnostics}: the map replaces
     * what THIS plugin published for the file, so an empty array clears it and
     * another plugin's marks stay. The marks survive a tab switch. A path with
     * no open buffer is a silent no-op.
     *
     * @param array<int, string> $marks 0-based line => one-line summary
     */
    public function setCommentMarks(string $path, array $marks): void
    {
        $runtime = ($this->runtime)();
        $known = [];
        foreach ($marks as $line => $summary) {
            $known[\max(0, $line)] = $summary;
        }
        $runtime->changeMarks->applyCommentMarks($path, $known, $this->pluginName);
    }

    /**
     * Show a dim trailing note after each of the ACTIVE document's lines — the
     * slot `git blame` uses. The map replaces whatever THIS plugin was showing,
     * so an empty array clears it; another plugin's notes stay, and a line two
     * plugins annotate shows both.
     *
     * Unlike {@see setDiagnostics} this takes no path: annotations belong to
     * whatever the editor is showing, and the widget drops them on every
     * document show. A plugin that wants them to survive a buffer switch
     * re-publishes from {@see onBufferSwitched}.
     *
     * @param array<int, string> $byLine 0-based line => the text shown after it
     */
    public function setLineAnnotations(array $byLine): void
    {
        $runtime = ($this->runtime)();
        $runtime->annotations->setLineAnnotations($byLine, $this->pluginName);
        $runtime->tui->requestRender();
    }

    /**
     * Show rows of text UNDER lines of the ACTIVE document — a whole review
     * conversation under the line it is about. Each row is plain text painted
     * dim; the line grows by as many rows, the way a code lens adds one above.
     *
     * Active-document-only and plugin-scoped, exactly like
     * {@see setLineAnnotations}: the map replaces what THIS plugin showed, an
     * empty array clears it, and the editor drops every block on a document
     * show, so re-publish from {@see onBufferSwitched}.
     *
     * @param array<int, list<string>> $byLine 0-based line => the rows under it
     */
    public function setLineBlocks(array $byLine): void
    {
        $runtime = ($this->runtime)();
        $runtime->annotations->setLineBlocks($byLine, $this->pluginName);
        $runtime->tui->requestRender();
    }

    // --- Loader/manager plumbing ---------------------------------------------

    public function pluginName(): string
    {
        return $this->pluginName;
    }

    /**
     * What activate() registered through the extension points the loader
     * drains wholesale — a snapshot, so writing to it registers nothing.
     */
    public function contributions(): PluginContributions
    {
        return clone $this->contributions;
    }

    /** @return list<array{plugin: string, id: string, label: string, handler: \Closure(): void, keys: list<string>}> */
    public function bufferedCommands(): array
    {
        return $this->commands;
    }

    /** @return list<array{id: string, when: (\Closure(): bool)|null, surface: MenuSurface}> */
    public function bufferedMenuItems(): array
    {
        return $this->menuItems;
    }

    /** @return list<array{0: class-string, 1: \Closure(object): void}> */
    public function bufferedHooks(): array
    {
        $this->drained = true;

        return $this->hooks;
    }

    /** @return list<array{key: string, label: string, kind: string, default: bool|int|string, options: list<string>|null, hint: string|null, min: int|null, max: int|null, step: int|null}> */
    public function bufferedSettings(): array
    {
        return \array_values($this->settingDescriptors);
    }
}
