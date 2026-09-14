<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// `composer rector:check` is a gate: a dry run that fails on any change Rector
// would still make. `composer rector` applies them — follow it with
// `composer format`, because Rector reprints the nodes it touches and the
// formatter never re-wraps, so its line breaks are the ones that stay.
//
// The paths and skips mirror mago's [source] block: the code this project
// ships and grades, minus vendored plugin dependencies, deliberately broken
// fixtures and the standalone mock-server scripts.
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/plugins',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/plugins/*/vendor',
        __DIR__.'/tests/Support/fixtures',
        __DIR__.'/tests/Support/*-lsp-server.php',
        __DIR__.'/tests/Support/lsp-stub.php',
        __DIR__.'/tests/Support/mock-dap-adapter.php',
    ])
    // Plugin classes resolve from their own manifests, not from composer.
    ->withBootstrapFiles([__DIR__.'/tests/plugin-autoload.php'])
    // mago's ambiguous-function-call wants `\sprintf()`; importing names would
    // turn every one back into an ambiguous call.
    ->withImportNames(importNames: false, importDocBlockNames: false)
    ->withParallel();
