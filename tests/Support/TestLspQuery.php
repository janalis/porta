<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\BufferManager;
use Porta\Editor\LineTextReader;
use Porta\I18n\Translator;
use Porta\Lsp\LspQuery;
use Porta\Lsp\LspSessionPool;
use Porta\Support\ProjectPaths;

/**
 * An {@see LspQuery} for a hand-built {@see \Porta\Plugin\Runtime\PluginRuntime}:
 * over the buffers given, or none, and a pool that serves no language unless
 * the test hands its own — so a runtime that never asks a server costs one line.
 */
final class TestLspQuery
{
    public static function create(?BufferManager $buffers = null, ?LspSessionPool $pool = null, string $root = '/p'): LspQuery
    {
        $buffers ??= new BufferManager();

        return new LspQuery(
            $pool ?? new LspSessionPool($root, new FixedActiveFile(), new Translator('en')),
            $buffers,
            new LineTextReader($buffers),
            new ProjectPaths($root),
        );
    }
}
