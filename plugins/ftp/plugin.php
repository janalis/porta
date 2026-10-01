<?php

declare(strict_types=1);

use Porta\Config\PortaHome;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;
use Porta\FtpPlugin\Credentials;
use Porta\FtpPlugin\FtpTransport;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\RemoteProjectPlugin;

/**
 * Projects on an FTP or FTPS server: `porta ftp ftp://user@host/path` opens
 * one, and while it is open this plugin moves its files with curl. Core keeps
 * the cache and decides what to fetch and when
 * ({@see \Porta\Filesystem\RemoteFilesystem}); {@see FtpTransport} only lists,
 * reads and writes.
 *
 * Inert for a project on this machine: nothing here runs unless the project
 * open is an `ftp://` or `ftps://` one.
 */
return new class('ftp') extends AbstractPlugin implements RemoteProjectPlugin {
    private ?PluginApi $api = null;

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('binary', 'curl binary', 'text', 'curl', hint: 'the curl to run; a name on PATH or a full path');
        $this->api = $api;
    }

    public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
    {
        $home = PortaHome::base();
        if (!\in_array($spec->scheme, ['ftp', 'ftps'], strict: true) || null === $home) {
            return null;
        }
        $binary = \trim((string) $this->api?->setting('binary'));

        return new FtpTransport($spec, Credentials::find($home, $spec), '' === $binary ? 'curl' : $binary);
    }
};
