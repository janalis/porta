<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * The single source of truth for "what language is this file?".
 *
 * Porta answers that question in two different vocabularies, and they are not
 * interchangeable:
 *
 * - the **highlighting** grammar name ({@see highlightForPath()}), which is
 *   `null` only when no backend could possibly colour the file. It is not
 *   Tempest-specific: any registered backend competes for these names, and a
 *   name the built-in engine doesn't know simply renders plain until a plugin
 *   claims it;
 * - the **LSP** `languageId` sent on `didOpen`
 *   ({@see ProjectPaths::languageId}), which falls back to `plaintext` and also
 *   keys the per-language indent settings and the debug-adapter lookup.
 *
 * They deliberately differ: `.tf` highlights as `terraform` but has no language
 * server, and `tsconfig.json` highlights as `jsonc` while LSP must still be told
 * `json`. Keeping both columns in one table is what makes those differences
 * visible instead of accidental — the two maps previously drifted apart in
 * separate files ({@see \Porta\Tests\Unit\Support\LanguageMapTest} pins the
 * per-extension results, and LanguageCatalogTest pins the catalog against the
 * LSP column).
 */
final class LanguageMap
{
    /**
     * extension => [highlighting grammar, LSP languageId or null for plaintext]
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    private const array EXTENSIONS = [
        'php' => ['php', 'php'],
        'phtml' => ['php', 'php'],
        'js' => ['javascript', 'javascript'],
        'mjs' => ['javascript', 'javascript'],
        'jsx' => ['javascript', null],
        'ts' => ['typescript', 'typescript'],
        'tsx' => ['typescript', null],
        'py' => ['python', 'python'],
        'json' => ['json', 'json'],
        'jsonc' => ['jsonc', 'json'],
        'json5' => ['jsonc', 'json'],
        'css' => ['css', 'css'],
        'scss' => ['scss', 'scss'],
        'html' => ['html', 'html'],
        'htm' => ['html', 'html'],
        'xml' => ['xml', 'xml'],
        'twig' => ['twig', 'twig'],
        'blade' => ['blade', null],
        'md' => ['markdown', 'markdown'],
        'yaml' => ['yaml', 'yaml'],
        'yml' => ['yaml', 'yaml'],
        'sql' => ['sql', null],
        'sh' => ['bash', null],
        'bash' => ['bash', null],
        'zsh' => ['bash', null],
        'env' => ['dotenv', 'dotenv'],
        'ini' => ['ini', null],
        'neon' => ['ini', null],
        'dockerfile' => ['dockerfile', null],
        'vue' => ['vue', null],
        'svelte' => ['svelte', null],
        'graphql' => ['graphql', null],
        'tf' => ['terraform', null],
        'diff' => ['diff', null],
        'patch' => ['diff', null],

        // Languages Porta has no built-in grammar for. Tempest returns the
        // source unchanged for a name it doesn't know, so these render exactly
        // as they did when they were absent from this map — but naming them
        // makes them *reachable*: a backend that does know them (see the bundled
        // chroma plugin) can now claim them. Without an entry here the language
        // resolves to null and no backend is asked at all.
        'rs' => ['rust', null],
        'go' => ['go', null],
        'rb' => ['ruby', null],
        'ex' => ['elixir', null],
        'exs' => ['elixir', null],
        'zig' => ['zig', null],
        'java' => ['java', null],
        'kt' => ['kotlin', null],
        'kts' => ['kotlin', null],
        'swift' => ['swift', null],
        'c' => ['c', null],
        'h' => ['c', null],
        'cpp' => ['cpp', null],
        'cc' => ['cpp', null],
        'hpp' => ['cpp', null],
        'cs' => ['csharp', null],
        'lua' => ['lua', null],
        'dart' => ['dart', null],
        'scala' => ['scala', null],
        'hs' => ['haskell', null],
        'clj' => ['clojure', null],
        'jl' => ['julia', null],
        'nim' => ['nim', null],
        'toml' => ['toml', null],
        'erl' => ['erlang', null],
        'ml' => ['ocaml', null],
    ];

    /**
     * Files whose extension lies about their grammar, by lowercased basename.
     *
     * The `*config.json` pair carries comments in practice (the TypeScript
     * compiler allows them), so they highlight as `jsonc`; the rest are strict
     * JSON under a name the extension map cannot see. LSP still resolves these
     * by extension, which is why they are highlight-only.
     *
     * @var array<string, string>
     */
    private const array FILENAMES = [
        'dockerfile' => 'dockerfile',
        'tsconfig.json' => 'jsonc',
        'jsconfig.json' => 'jsonc',
        'composer.lock' => 'json',
        '.babelrc' => 'json',
        '.prettierrc' => 'json',
        '.eslintrc' => 'json',
    ];

    /** The Tempest highlighting grammar for a path, or null when unknown. */
    public static function highlightForPath(string $path): ?string
    {
        $path = self::withoutDistSuffix($path);
        $name = \strtolower(\basename($path));
        $mapped = self::FILENAMES[$name] ?? self::EXTENSIONS[ProjectPaths::extension($path)][0] ?? null;

        // The `.env*` basename fallback runs AFTER the lookups, same as the
        // LSP column: `.env.local.php` is real PHP, not a dotenv file.
        return $mapped ?? (\str_starts_with($name, '.env') ? 'dotenv' : null);
    }

    /**
     * A fenced code block's language name that is not a file extension, as GitHub reads it.
     *
     * @var array<string, string>
     */
    private const array FENCE_ALIASES = [
        'shell' => 'bash',
        'console' => 'bash',
        'sh-session' => 'bash',
        'shell-session' => 'bash',
        'zsh' => 'bash',
        'js' => 'javascript',
        'node' => 'javascript',
        'ts' => 'typescript',
        'py' => 'python',
        'yml' => 'yaml',
        'dotenv' => 'dotenv',
        'docker' => 'dockerfile',
        'patch' => 'diff',
    ];

    /**
     * The grammar for a fenced code block's language (` ```php `): a grammar
     * name, an extension, or one of GitHub's aliases; null when unknown.
     */
    public static function highlightForFence(string $language): ?string
    {
        $language = \strtolower(\trim($language));

        return match (true) {
            '' === $language => null,
            \array_key_exists($language, self::FENCE_ALIASES) => self::FENCE_ALIASES[$language],
            \in_array($language, self::grammars(), strict: true) => $language,
            default => self::EXTENSIONS[$language][0] ?? null,
        };
    }

    /** The LSP languageId for a path; `plaintext` when no server speaks it. */
    public static function lspForPath(string $path): string
    {
        $path = self::withoutDistSuffix($path);
        $mapped = self::EXTENSIONS[ProjectPaths::extension($path)][1] ?? null;

        // `.env.local` / `.env.dev` resolve their EXTENSION as `local`/`dev`,
        // so without this basename fallback only a bare `.env` would ever
        // reach the dotenv server. It runs AFTER the extension lookup: a
        // `composer dump-env` product like `.env.local.php` is real PHP and
        // must keep routing to the PHP server, not be announced as dotenv.
        return $mapped ?? (\str_starts_with(\strtolower(\basename($path)), '.env') ? 'dotenv' : 'plaintext');
    }

    /**
     * Every distinct highlighting grammar name the map can produce, sorted.
     *
     * The settings page probes backends against this list to work out which
     * languages actually offer a choice — `supports()` is the only thing a
     * provider has to implement, so there is no "what do you claim?" to ask.
     *
     * @return list<string>
     */
    public static function grammars(): array
    {
        $grammars = [];
        foreach (self::EXTENSIONS as [$grammar]) {
            $grammars[$grammar] = true;
        }
        foreach (self::FILENAMES as $grammar) {
            $grammars[$grammar] = true;
        }
        $names = \array_keys($grammars);
        \sort($names);

        return $names;
    }

    /**
     * A `.dist` file is the file it will be copied to: `phpunit.xml.dist` is
     * XML, `services.yaml.dist` is YAML, `.env.dist` is a dotenv file. The
     * suffix is a packaging convention, not a language — resolved by it, such a
     * file had the extension `dist` and rendered as plain text. Only a trailing
     * suffix counts, and only on the file name (a `vendor.dist/` DIRECTORY
     * changes nothing), so `a.dist` alone is still a file with no language.
     */
    private static function withoutDistSuffix(string $path): string
    {
        return \str_ends_with(\strtolower(\basename($path)), '.dist') ? \substr($path, offset: 0, length: -5) : $path;
    }
}
