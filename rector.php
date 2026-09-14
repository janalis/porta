<?php

declare(strict_types=1);

use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php53\Rector\Ternary\TernaryToElvisRector;

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

        // Renames the constructor parameter to the property's name (breaking
        // named-argument call sites), drops the comment that explained the
        // assignment, and folds a broken parameter list onto one line the
        // formatter will never re-wrap.
        ClassPropertyAssignToConstructorPromotionRector::class,
        // A closure with a statement body is sometimes the point: plugin files
        // carry `@mago-expect lint:prefer-arrow-function` where an arrow would
        // hide an assignment inside an expression.
        ClosureToArrowFunctionRector::class,
        // Several class-name strings are strings on purpose: a plugin class
        // named from core tests (core keeps no plugin symbol), the arch
        // allowlist, a resolver's positive control, and scanner fixtures whose
        // classes do not exist, which would leave a mixed list.
        StringClassNameToClassConstantRector::class,
        // `?:` is exactly what mago's no-shorthand-ternary rule forbids.
        TernaryToElvisRector::class,
        // `\is_string(...)` filters exactly like the arrow function, but mago
        // cannot narrow through a first-class callable: the list goes back to
        // list<mixed> and the declared return shape stops holding.
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
            __DIR__.'/src/Debug/DebugStateStore.php',
        ],
    ])
    // Plugin classes resolve from their own manifests, not from composer.
    ->withBootstrapFiles([__DIR__.'/tests/plugin-autoload.php'])
    // mago's ambiguous-function-call wants `\sprintf()`; importing names would
    // turn every one back into an ambiguous call.
    ->withImportNames(importNames: false, importDocBlockNames: false)
    ->withPhpSets(php85: true)
    ->withParallel();
