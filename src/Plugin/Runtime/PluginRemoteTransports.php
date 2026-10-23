<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;
use Porta\Filesystem\RemoteTransportSource;

/**
 * The remote transports the active plugins offer: the first
 * {@see \Porta\Plugin\Api\RemoteProjectPlugin} to answer for a spec serves
 * it. One that throws is reported against its plugin, and the next is asked.
 */
final readonly class PluginRemoteTransports implements RemoteTransportSource
{
    public function __construct(
        private PluginManager $plugins,
    ) {}

    #[\Override]
    public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
    {
        $transport = null;
        foreach ($this->plugins->remoteProjectPlugins() as $name => $plugin) {
            try {
                $transport ??= $plugin->remoteTransport($spec);
            } catch (\Throwable $error) {
                $this->plugins->reportFailure((string) $name, $error); // a name in digits keys as an int
            }
        }

        return $transport;
    }
}
