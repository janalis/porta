<?php

declare(strict_types=1);

use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\CodeQuality\Rector\BooleanNot\SimplifyDeMorganBinaryRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\CodeQuality\Rector\For_\ForRepeatedCountToOwnVariableRector;
use Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector;
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\DeadCode\Rector\FunctionLike\NarrowWideUnionReturnTypeRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Concat\RemoveConcatAutocastRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\Instanceof_\Rector\Ternary\FlipNegatedTernaryInstanceofRector;
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
        // `null === $x` becomes `!$x instanceof Foo`: not how Symfony writes a
        // null check, no longer yoda, and each one imports a class — new
        // namespace edges for the arch gate to record, for no behaviour.
        FlipTypeControlToUseExclusiveTypeRector::class,
        FlipNegatedTernaryInstanceofRector::class,
        // A private static helper is static so a `static fn` can call it;
        // making it an instance method would force `$this` into closures
        // that must not capture it.
        LocallyCalledStaticMethodToNonStaticRector::class,
        // Reordering named arguments to declaration order also reorders
        // their evaluation, and the order they are written in is often the
        // order a reader should meet them.
        SortCallLikeNamedArgsRector::class,
        // Hoists the count into a variable named `$counter`, which reads as
        // a counter; the loop bound was clearer inline.
        ForRepeatedCountToOwnVariableRector::class,
        // `\dirname(__DIR__).'/docs'` becomes `__DIR__.'/../docs'`: a different
        // string for the same directory, and paths here are compared, hashed
        // into cache keys and shown to the user.
        DirnameDirConcatStringToDirectStringPathRector::class,
        // `!(a || b)` becomes `!a && !b`, and next to an `||` the parentheses
        // were what made the grouping readable.
        SimplifyDeMorganBinaryRector::class,
        // Threads `$e->getCode()` into the rethrow, but a Throwable's code is
        // int|string and the exception constructor takes int; `previous:`
        // already carries the cause.
        ThrowWithPreviousExceptionRector::class,
        // An event listener's parameter is how the dispatcher knows which event
        // it hears, used in the body or not; and a public signature is API a
        // plugin may call.
        RemoveUnusedPublicMethodParameterRector::class,
        // Both read a variable captured BY REFERENCE as the constant it starts
        // as — `use (&$fail)` flipped later — and delete the branch the test
        // exists to take.
        RemoveAlwaysTrueIfConditionRector::class,
        RemoveUnreachableStatementRector::class,
        // The same trap in a return type: `?string` narrowed to `string` on a
        // closure whose by-reference capture later makes it return null.
        NarrowWideUnionReturnTypeRector::class,
        // An empty override is how a test double swallows what its parent
        // would do.
        RemoveEmptyClassMethodRector::class,
        // mago, not phpstan, grades this tree. A `/** @var mixed $x */` is how
        // a deliberate mixed assignment is acknowledged, and an inline
        // `/** @var T */` on a return is what mago narrows from; both look
        // useless to Rector's phpstan and are not.
        RemoveUselessVarTagRector::class,
        RemoveNonExistingVarAnnotationRector::class,
        // Rector trusts phpstan's stubs that a call returns string; mago's say
        // string|false (mb_convert_encoding, tempnam, json_encode), and the
        // cast is the narrowing.
        RecastingRemovalRector::class,
        RemoveConcatAutocastRector::class,
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
            __DIR__.'/src/Debug/DebugStateStore.php',
        ],
    ])
    // Plugin classes resolve from their own manifests, not from composer.
    ->withBootstrapFiles([__DIR__.'/tests/plugin-autoload.php'])
    // A type Rector adds is imported, not spelled `\Symfony\Component\Tui\Tui`
    // inline — but only in a file Rector is already changing, and only class
    // names: native functions stay `\sprintf()`, which is what mago's
    // ambiguous-function-call demands, and global classes stay `\Closure`.
    // Docblocks are prose here and are left alone.
    ->withImportNames(importNames: true, importDocBlockNames: false, importShortClasses: false, removeUnusedImports: false)
    ->withPhpSets(php85: true)
    ->withPreparedSets(typeDeclarations: true, codeQuality: true, deadCode: true)
    ->withParallel();
