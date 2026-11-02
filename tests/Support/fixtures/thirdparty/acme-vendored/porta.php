<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;

/** A plugin whose own class calls into a package it vendored with composer. */
return new class('acme-vendored') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        $api->registerCommand('greet', 'Acme: Greet', static fn () => $api->flash(Acme\Vendored\Greeter::greet()));
    }
};
