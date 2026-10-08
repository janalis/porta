<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The built-in live templates, keyed by language id
 * ({@see \Porta\Support\ProjectPaths::languageId}).
 *
 * A template body is an **LSP snippet** — the same syntax the language server's
 * own completions arrive in, parsed by {@see SnippetParser} and driven by
 * {@see SnippetSession}. No second template language was invented: `$1`/`$2`
 * are the tab stops, `${1:default}` a placeholder and `$0` the final caret,
 * while a bare `$name` stays literal, which is why PHP variables need no
 * escaping.
 *
 * A POSTFIX template's key starts with a dot. It rewrites the expression to the
 * left of that dot, which arrives in the body as `{{expr}}` — substituted before
 * the snippet is parsed, so an expression containing a `$` can never be read as
 * snippet syntax.
 */
final class TemplateCatalog
{
    /** The token a postfix template's body uses for the expression it wraps. */
    public const string EXPRESSION = '{{expr}}';

    /** @var array<string, array<string, string>> language id => abbreviation => snippet body */
    private const array TEMPLATES = [
        'php' => [
            'fore' => "foreach (\${1:\$items} as \${2:\$item}) {\n    \$0\n}",
            'forek' => "foreach (\${1:\$items} as \${2:\$key} => \${3:\$value}) {\n    \$0\n}",
            'fori' => "for (\$i = 0; \$i < \${1:\$count}; ++\$i) {\n    \$0\n}",
            'ifn' => "if (null === \${1:\$value}) {\n    \$0\n}",
            'inn' => "if (null !== \${1:\$value}) {\n    \$0\n}",
            'thr' => "throw new \${1:\\RuntimeException}(\${2:'\$3'});\$0",
            'tryc' => "try {\n    \$0\n} catch (\${1:\\Throwable} \$e) {\n}",
            'pubf' => "public function \${1:name}(\${2}): \${3:void}\n{\n    \$0\n}",
            'prif' => "private function \${1:name}(\${2}): \${3:void}\n{\n    \$0\n}",
            'ctor' => "public function __construct(\n    \$0\n) {}",
            'sout' => 'echo ${1:$value};$0',
            'vdd' => 'var_dump(${1:$value});$0',
            // Postfix: the expression to the left of the dot.
            '.fore' => "foreach ({{expr}} as \${1:\$item}) {\n    \$0\n}",
            '.if' => "if ({{expr}}) {\n    \$0\n}",
            '.ifn' => "if (null === {{expr}}) {\n    \$0\n}",
            '.inn' => "if (null !== {{expr}}) {\n    \$0\n}",
            '.not' => '!{{expr}}$0',
            '.var' => '${1:$value} = {{expr}};$0',
            '.ret' => 'return {{expr}};$0',
            '.dump' => 'var_dump({{expr}});$0',
        ],
        'javascript' => [
            'fore' => "for (const \${1:item} of \${2:items}) {\n    \$0\n}",
            'fn' => "function \${1:name}(\${2}) {\n    \$0\n}",
            'sout' => 'console.log(${1:value});$0',
            '.log' => 'console.log({{expr}});$0',
            '.if' => "if ({{expr}}) {\n    \$0\n}",
            '.not' => '!{{expr}}$0',
            '.ret' => 'return {{expr}};$0',
        ],
    ];

    /** @return array<string, string> abbreviation => snippet body; empty for a language with none */
    public static function forLanguage(string $languageId): array
    {
        // TypeScript is JavaScript's set: every entry here is syntax the two
        // share, and duplicating them would be two places to fix one typo.
        return self::TEMPLATES['typescript' === $languageId ? 'javascript' : $languageId] ?? [];
    }
}
