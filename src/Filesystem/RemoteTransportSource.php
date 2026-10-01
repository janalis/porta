<?php

declare(strict_types=1);

namespace Porta\Filesystem;

/**
 * The transports plugins contribute — the {@see \Porta\Debug\DebugAdapterProvider}
 * arrangement: the remote filesystem asks this rather than depending on the
 * plugin subsystem, and {@see \Porta\Plugin\Runtime\PluginManager} answers.
 */
interface RemoteTransportSource
{
    /** A transport for $spec from the first plugin serving its scheme, or null when none does. */
    public function remoteTransport(RemoteSpec $spec): ?RemoteTransport;
}
