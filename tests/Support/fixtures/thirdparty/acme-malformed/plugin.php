<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;

/** Ships nothing; it exists for the vendor maps beside it. */
return new class('acme-malformed') extends AbstractPlugin {
    public function activate(PluginApi $api): void {}
};
