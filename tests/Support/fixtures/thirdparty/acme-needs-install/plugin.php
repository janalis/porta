<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;

/** Declares composer dependencies and has no vendor/: must never be activated. */
return new class('acme-needs-install') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        $api->registerCommand('boom', 'Acme: Boom', static fn () => null);
    }
};
