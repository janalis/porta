<?php

declare(strict_types=1);

namespace Porta\Tests\Support\Screenshots;

/**
 * Every docs screenshot by name — what a page references as
 * `/screenshots/<name>.svg`. The docs gate holds the pages to this list in both
 * directions, and {@see TakesScreenshots} refuses a name that is not on it, so
 * an image a page shows can never be one the build does not draw.
 */
final class Screenshots
{
    /** Each taken by a scenario of its own, in tests/E2e/Screenshots. */
    public const array SCENES = [
        'overview',
        'completion',
        'find-action',
        'find-in-project',
        'settings',
        'terminal',
        'debugger',
        'plugin-composer',
        'plugin-diff',
        'plugin-git',
        'plugin-graph-viewer',
        'plugin-image-viewer',
        'plugin-local-history',
        'plugin-phpunit',
        'plugin-review',
        'plugin-todo',
    ];

    /**
     * The plugins shown on their Settings page: each wraps a tool or a server,
     * whose effect needs the real binary, and the page is where its guide
     * sends the reader first.
     */
    public const array SETTINGS_PAGES = [
        'assist',
        'chroma',
        'custos',
        'ftp',
        'mago',
        'mcp',
        'php-cs-fixer',
        'phpactor',
        'phpantom',
        'phpstan',
        'psalm',
        'ssh',
        'symfony-lsp',
        'tempest',
        'xdebug',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return [...self::SCENES, ...\array_map(static fn (string $plugin): string => 'plugin-'.$plugin, self::SETTINGS_PAGES)];
    }
}
