<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

/**
 * Can this plugin's external tool be installed from inside Porta, and is it
 * there yet?
 *
 * The slice the two OFFER paths need — the plugin's settings page, which shows
 * the button, and the welcome flow, which has to know whether a card is really
 * done — as opposed to the whole of {@see PluginManager}, which is far more
 * than either of them should be able to reach. It is the same split
 * {@see \Porta\Plugin\Lifecycle\PluginEnablement} makes for the enable verb.
 */
interface ToolInstallOffers
{
    /**
     * The install offer $plugin declared for its tool binary, or null when it
     * declared none. `available` is re-evaluated on every call, so a caller
     * may treat it as live; `describe` is meaningful only while it holds.
     *
     * @return array{tool: string, available: bool, via: list<array{0: string, 1: string}>, describe: string}|null
     */
    public function installOffer(string $plugin): ?array;
}
