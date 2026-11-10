<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Workbench\Menu\MenuContribution;

/**
 * What one plugin's `activate()` registered through the extension points the
 * loader drains wholesale — one {@see PluginBuffer} per point, appended to by
 * {@see PluginApi}'s register verbs and read once by
 * {@see \Porta\Plugin\Runtime\PluginManager::commit()}.
 *
 * Its own object because every buffer is touched by exactly three places —
 * built here, appended by its verb, drained at commit — and none by anything
 * else: sixteen drains were sixteen public methods on the plugin-facing API
 * that no plugin may call. The four plain arrays with semantics of their own
 * (command ids, menu specs, hooks, settings) stay on the API.
 */
final readonly class PluginContributions
{
    /** @var PluginBuffer<array{plugin: string, languageId: string, name: string, role: ServerRole, resolve: \Closure(): (list<string>|ServerNotFound|null), initOptions: (\Closure(): array<string, mixed>)|null}> */
    public PluginBuffer $languageServers;
    /** @var PluginBuffer<array{plugin: string, tool: string, available: \Closure(): bool, via: list<array{0: string, 1: string}>, describe: (\Closure(): string)|null}> */
    public PluginBuffer $installers;
    /** @var PluginBuffer<array{plugin: string, id: string, label: string, languages: \Closure(): list<string>, highlight: \Closure(string, string, \Closure(string): void): void, available: (\Closure(): bool)|null}> */
    public PluginBuffer $highlighters;
    /** @var PluginBuffer<array{plugin: string, languages: \Closure(): list<string>, classify: \Closure(string, string): (list<array{start: int, end: int}>|null)}> */
    public PluginBuffer $tokenSpans;
    /** @var PluginBuffer<array{plugin: string, id: string, claims: \Closure(string): bool}> */
    public PluginBuffer $viewers;
    /** @var PluginBuffer<array{plugin: string, id: string, claims: \Closure(string): bool}> */
    public PluginBuffer $previews;
    /** @var PluginBuffer<array{plugin: string, languages: list<string>, render: \Closure(string, string, string, int, \Closure(\Porta\Help\Fence\FenceResult): void, \Closure(string): void): \Closure(): void}> */
    public PluginBuffer $markdownFences;
    /** @var PluginBuffer<array{plugin: string, capability: Capability, provider: \Closure}> */
    public PluginBuffer $capabilities;
    /** @var PluginBuffer<array{plugin: string, id: string, pane: \Closure, load: \Closure}> */
    public PluginBuffer $panes;
    /** @var PluginBuffer<array{plugin: string, id: string, factory: \Closure}> */
    public PluginBuffer $views;
    /** @var PluginBuffer<array{plugin: string, id: string, label: string}> */
    public PluginBuffer $actions;
    /** @var PluginBuffer<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}> */
    public PluginBuffer $listTools;
    /** @var PluginBuffer<array{plugin: string, contribution: MenuContribution}> */
    public PluginBuffer $menus;
    /** @var PluginBuffer<array{plugin: string, listener: \Closure(string, bool|int|string): void}> */
    public PluginBuffer $settingHooks;
    /** @var PluginBuffer<array{plugin: string, segment: \Closure(): (string|\Porta\Workbench\StatusCell|null)}> */
    public PluginBuffer $statusSegments;
    /** @var PluginBuffer<array{plugin: string, languageId: string, resolve: \Closure(): (list<string>|null)}> */
    public PluginBuffer $debugAdapters;
    /** @var PluginBuffer<array{plugin: string, id: string, description: string, inputSchema: array<string, mixed>, handler: \Closure(array<string, mixed>, ToolCaller=): (string|array<array-key, mixed>), effect: ?ToolEffect}> */
    public PluginBuffer $tools;

    public function __construct()
    {
        $this->languageServers = new PluginBuffer();
        $this->installers = new PluginBuffer();
        $this->highlighters = new PluginBuffer();
        $this->tokenSpans = new PluginBuffer();
        $this->viewers = new PluginBuffer();
        $this->previews = new PluginBuffer();
        $this->markdownFences = new PluginBuffer();
        $this->capabilities = new PluginBuffer();
        $this->panes = new PluginBuffer();
        $this->views = new PluginBuffer();
        $this->actions = new PluginBuffer();
        $this->listTools = new PluginBuffer();
        $this->menus = new PluginBuffer();
        $this->settingHooks = new PluginBuffer();
        $this->statusSegments = new PluginBuffer();
        $this->debugAdapters = new PluginBuffer();
        $this->tools = new PluginBuffer();
    }

    /**
     * A copy owns copies of the buffers: {@see PluginApi::contributions()} hands
     * out a snapshot, so a row added to it reaches nothing — the register verbs,
     * which validate and stamp each row, stay the only way in.
     */
    public function __clone()
    {
        $this->languageServers = clone $this->languageServers;
        $this->installers = clone $this->installers;
        $this->highlighters = clone $this->highlighters;
        $this->tokenSpans = clone $this->tokenSpans;
        $this->viewers = clone $this->viewers;
        $this->previews = clone $this->previews;
        $this->markdownFences = clone $this->markdownFences;
        $this->capabilities = clone $this->capabilities;
        $this->panes = clone $this->panes;
        $this->views = clone $this->views;
        $this->actions = clone $this->actions;
        $this->listTools = clone $this->listTools;
        $this->menus = clone $this->menus;
        $this->settingHooks = clone $this->settingHooks;
        $this->statusSegments = clone $this->statusSegments;
        $this->debugAdapters = clone $this->debugAdapters;
        $this->tools = clone $this->tools;
    }
}
