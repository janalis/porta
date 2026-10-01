<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;

/**
 * A plugin that opens projects on a server: when the open project is a remote
 * one, each such plugin is asked in load order for the
 * {@see RemoteTransport} that moves its files, and the first to answer serves
 * it. Asked once, after every plugin activated — never during `activate()`.
 *
 * Core keeps the cache, decides what to fetch and when, and orders the
 * uploads; the transport only lists, reads and writes. Opening such a project
 * is {@see \Porta\Filesystem\ProjectLocation::prepare()}, then pointing Porta
 * at the directory it answers.
 *
 * Opt-in beside {@see PortaPlugin}, as {@see DisposablePlugin} is, so
 * implementors write `extends AbstractPlugin implements RemoteProjectPlugin`.
 */
interface RemoteProjectPlugin
{
    /** A transport for $spec, or null when its scheme is not this plugin's. */
    public function remoteTransport(RemoteSpec $spec): ?RemoteTransport;
}
