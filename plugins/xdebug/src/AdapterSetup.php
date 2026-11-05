<?php

declare(strict_types=1);

namespace Porta\Xdebug;

use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;

/**
 * The Install button: one install at a time, said as it goes — the download
 * takes a while, and a button that answers nothing gets pressed again.
 */
final class AdapterSetup
{
    private const string SLOT = 'xdebug:install';

    private bool $installing = false;

    /**
     * @param \Closure(): AdapterLocator            $locate
     * @param \Closure(string): AdapterInstaller    $installer for the directory to install into
     */
    public function __construct(
        private readonly PluginApi $api,
        private readonly \Closure $locate,
        private readonly \Closure $installer,
    ) {}

    public function install(): void
    {
        $target = ($this->locate)()->installDir();
        if ($this->installing || null === $target) {
            $this->api->flash(
                $this->installing
                    ? $this->api->trans('The php-debug adapter is already being installed')
                    : $this->api->trans('No home directory to install the php-debug adapter into'),
            );

            return;
        }
        $this->installing = true;
        $this->api->notify($this->api->trans('Installing the php-debug adapter %version%…', ['%version%' => AdapterLocator::VERSION]), seconds: 30.0, slot: self::SLOT);
        ($this->installer)($target)->install(function (?string $failure): void {
            $this->installing = false;
            null === $failure
                ? $this->api->notify(
                    $this->api->trans('php-debug %version% installed: Shift+F9 debugs the PHP file in front', ['%version%' => AdapterLocator::VERSION]),
                    ToastSeverity::Success,
                    5.0,
                    self::SLOT,
                )
                : $this->api->notify($this->api->trans('The php-debug adapter was not installed: %reason%', ['%reason%' => $failure]), ToastSeverity::Error, 6.0, self::SLOT);
        });
    }
}
