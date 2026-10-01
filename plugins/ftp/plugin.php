<?php

declare(strict_types=1);

use Porta\Config\PortaHome;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteTransport;
use Porta\FtpPlugin\Credentials;
use Porta\FtpPlugin\FtpTransport;
use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\RemoteProjectPlugin;
use Porta\Ui\TextEcho;

/**
 * Projects on an FTP or FTPS server: `porta ftp ftp://user@host/path` opens
 * one, and while it is open this plugin moves its files with curl. Core keeps
 * the cache and decides what to fetch and when
 * ({@see \Porta\Filesystem\RemoteFilesystem}); {@see FtpTransport} only lists,
 * reads and writes.
 *
 * A project opened from inside Porta has had no terminal to ask for its
 * password on: the server's refusal is what asks, masked, and the password is
 * kept as `porta ftp` keeps it. Core asks the server again half a minute
 * later, which then logs in.
 *
 * Inert for a project on this machine: nothing here runs unless the project
 * open is an `ftp://` or `ftps://` one.
 */
return new class('ftp') extends AbstractPlugin implements RemoteProjectPlugin {
    private ?PluginApi $api = null;

    /** Whether the password was asked for and not given since: asked once, not at every refusal. */
    private bool $asked = false;

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

        return new FtpTransport($spec, Credentials::file($home, $spec), '' === $binary ? 'curl' : $binary, fn () => $this->askPassword($spec, $home));
    }

    /** The server refused the login: ask for the password, once, and keep what is given. */
    private function askPassword(RemoteSpec $spec, string $home): void
    {
        $api = $this->api;
        if ($this->asked || null === $spec->user || null === $api) {
            return; // asked already, or an anonymous login there is nothing to ask for
        }
        $this->asked = true;
        $label = $api->trans('Password for %user%@%host%', ['%user%' => $spec->user, '%host%' => $spec->host]);
        $api->prompt(
            $label,
            '',
            function (#[\SensitiveParameter] string $password) use ($api, $spec, $home): void {
                if (null === Credentials::store($home, $spec, $password)) {
                    $api->notify($api->trans('Cannot keep the password in %dir%', ['%dir%' => \dirname(Credentials::file($home, $spec))]), ToastSeverity::Error);

                    return;
                }
                $this->asked = false;
                $api->flash($api->trans('Password kept — the server is asked again shortly'));
            },
            TextEcho::Masked,
        );
    }
};
