<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;

/** A session pool whose one PHP server, `php-ls`, is the fake client $client makes — logging, diagnostics and edits answered by nothing. */
final class FakeLspPool
{
    /** @param \Closure(): FakeLspClient $client */
    public static function create(string $root, FixedActiveFile $activeFile, \Closure $client): LspSessionPool
    {
        $pool = new LspSessionPool($root, $activeFile, new Translator('en'), static fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $client());
        $pool->configure(
            ['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );

        return $pool;
    }
}
