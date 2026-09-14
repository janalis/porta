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

    /**
     * The extension a NEW file of each language gets — a scratch buffer has no
     * path to take its language from, so something has to choose one.
     *
     * A second table rather than a reverse lookup through
     * {@see \Porta\Support\LanguageMap}, whose map is one-way and answers for
     * fifty grammars this list does not offer. {@see \Porta\Tests\Unit\Config\LanguageCatalogTest}
     * is the gate: every id here must appear above, and every extension must
     * resolve back through the map to the id it is filed under.
     *
     * @var array<string, string> language id => extension, without the dot
     */
    public const array EXTENSIONS = [
        'php' => 'php',
        'javascript' => 'js',
        'typescript' => 'ts',
        'python' => 'py',
        'json' => 'json',
        'css' => 'css',
        'scss' => 'scss',
        'html' => 'html',
        'markdown' => 'md',
        'yaml' => 'yaml',
        'twig' => 'twig',
        'xml' => 'xml',
        'dotenv' => 'env',
    ];

    /** @return list<string> the configurable language ids, in display order */
    public static function ids(): array
    {
        return \array_keys(self::LANGUAGES);
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
