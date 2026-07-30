<?php

declare(strict_types=1);

use Acme\Tools\Greeting;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;

/**
 * A fixture shaped like a REAL third-party plugin: its own namespace, its own
 * class, loaded from a directory composer knows nothing about.
 */
return new class('acme-tools') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        $api->registerCommand('greet', 'Acme: Greet', static function () use ($api): void {
            $api->flash(Greeting::text());
        });
        $api->addMenuItem('greet');
    }
};
