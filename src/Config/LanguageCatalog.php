<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * The languages Porta offers per-language configuration for (indent style and
 * size in the settings screen). The ids match {@see \Porta\Support\ProjectPaths::languageId}
 * so a file resolves to one of these at open time; this is the single source of
 * truth for the id list and their display names.
 */
final class LanguageCatalog
{
    /** @var array<string, string> language id => human display name, in menu order */
    public const array LANGUAGES = [
        'php' => 'PHP',
        'javascript' => 'JavaScript',
        'typescript' => 'TypeScript',
        'python' => 'Python',
        'json' => 'JSON',
        'css' => 'CSS',
        'scss' => 'SCSS',
        'html' => 'HTML',
        'markdown' => 'Markdown',
        'yaml' => 'YAML',
        'twig' => 'Twig',
        'xml' => 'XML',
        'dotenv' => 'Dotenv',
    ];

    /** @return list<string> the configurable language ids, in display order */
    public static function ids(): array
    {
        return array_keys(self::LANGUAGES);
    }

    public static function isKnown(string $id): bool
    {
        return (self::LANGUAGES[$id] ?? null) !== null;
    }

    /** Human display name for a language id; the id itself for an unknown one. */
    public static function displayName(string $id): string
    {
        return self::LANGUAGES[$id] ?? $id;
    }
}
