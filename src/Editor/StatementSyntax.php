<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * How one language ends a statement and opens a block.
 *
 * The table {@see \Porta\Editor\Document::completeStatement()} needs, and the
 * reason that verb waited a round: a statement ends in `;` and a block opens
 * with `{` in neither Python nor YAML, so a PHP heuristic applied to every
 * buffer would put a semicolon where the language has none. That is the mistake
 * a mock server answering what no real one advertises makes, in a new place —
 * so this is a table, decided per grammar, with a gate over every name
 * {@see \Porta\Support\LanguageMap::grammars()} can produce.
 *
 * **`null` is a real answer here**, unlike in {@see CommentSyntax}. Every
 * language has SOME comment form, so a missing one there was pure omission;
 * JSON, Markdown, TOML and `.env` have no statements to complete at all, and
 * saying so is the honest row rather than a gap.
 *
 * A block's CLOSER is separately nullable, because Python's is the indent
 * itself: `if x` completes to `if x:` and a new indented line, with nothing to
 * put underneath.
 */
final class StatementSyntax
{
    /**
     * grammar => [statement terminator, [block opener, block closer|null], keywords that open a block]
     *
     * The opener is stored **exactly as it is appended**, leading space and
     * all — `' {'` for the brace languages, `':'` for Python. A separator rule
     * in the code would have to know that a brace is spaced off the condition
     * and a colon binds to it, which is a fact about each language and belongs
     * in each language's row.
     *
     * Choices worth defending:
     *
     * - **`go` has no terminator.** The compiler inserts semicolons and gofmt
     *   removes them, so writing one is the opposite of what the language
     *   wants — it still gets brace completion.
     * - **`css`/`scss` have `;` and no keywords.** A declaration ends in a
     *   semicolon; a rule's `{` follows a selector, which is not a keyword and
     *   cannot be recognised without parsing. Terminator only, deliberately.
     * - **`sql` has `;` and no braces**, and `ruby`/`lua` open blocks with
     *   words (`do`/`end`, `then`/`end`) whose closer is a WORD on its own
     *   line — a shape this table cannot express and which is left out rather
     *   than half-supported.
     * - **`python` closes nothing.** The block is the indent.
     *
     * @var array<string, array{0: string|null, 1: array{0: string, 1: string|null}|null, 2: list<string>}>
     */
    private const array SYNTAX = [
        'bash' => [null, null, []],
        'blade' => [null, null, []],
        'c' => [';', [' {', '}'], self::C_KEYWORDS],
        'clojure' => [null, null, []],
        'cpp' => [';', [' {', '}'], self::C_KEYWORDS],
        'csharp' => [';', [' {', '}'], self::C_KEYWORDS],
        'css' => [';', null, []],
        'dart' => [';', [' {', '}'], self::C_KEYWORDS],
        'diff' => [null, null, []],
        'dockerfile' => [null, null, []],
        'dotenv' => [null, null, []],
        'elixir' => [null, null, []],
        'erlang' => [null, null, []],
        'go' => [null, [' {', '}'], ['if', 'for', 'switch', 'select', 'func']],
        'graphql' => [null, null, []],
        'haskell' => [null, null, []],
        'html' => [null, null, []],
        'ini' => [null, null, []],
        'java' => [';', [' {', '}'], self::C_KEYWORDS],
        'javascript' => [';', [' {', '}'], self::JS_KEYWORDS],
        'json' => [null, null, []],
        'jsonc' => [null, null, []],
        'julia' => [null, null, []],
        'kotlin' => [';', [' {', '}'], self::C_KEYWORDS],
        'lua' => [null, null, []],
        'markdown' => [null, null, []],
        'nim' => [null, null, []],
        'ocaml' => [null, null, []],
        'php' => [';', [' {', '}'], self::PHP_KEYWORDS],
        'python' => [null, [':', null], ['if', 'elif', 'else', 'for', 'while', 'try', 'except', 'finally', 'with', 'def', 'class']],
        'ruby' => [null, null, []],
        'rust' => [';', [' {', '}'], ['if', 'for', 'while', 'loop', 'match', 'fn', 'impl', 'struct', 'enum', 'trait', 'mod', 'unsafe']],
        'scala' => [';', [' {', '}'], self::C_KEYWORDS],
        'scss' => [';', null, []],
        'sql' => [';', null, []],
        'svelte' => [null, null, []],
        'swift' => [';', [' {', '}'], ['if', 'for', 'while', 'guard', 'switch', 'func', 'class', 'struct', 'enum', 'extension', 'repeat']],
        'terraform' => [null, null, []],
        'toml' => [null, null, []],
        'twig' => [null, null, []],
        'typescript' => [';', [' {', '}'], self::JS_KEYWORDS],
        'vue' => [null, null, []],
        'xml' => [null, null, []],
        'yaml' => [null, null, []],
        'zig' => [';', [' {', '}'], ['if', 'for', 'while', 'switch', 'fn', 'struct', 'enum', 'union', 'defer']],
    ];

    /**
     * The braces-and-semicolons default.
     *
     * @var list<string>
     */
    private const array C_KEYWORDS = ['if', 'else', 'for', 'while', 'switch', 'try', 'catch', 'finally', 'do'];

    /** @var list<string> */
    private const array JS_KEYWORDS = [...self::C_KEYWORDS, 'function', 'class'];

    /** @var list<string> */
    private const array PHP_KEYWORDS = [...self::C_KEYWORDS, 'foreach', 'elseif', 'function', 'class', 'interface', 'trait', 'enum', 'match'];

    /**
     * @param array{0: string, 1: string|null}|null $block
     * @param list<string>                          $blockKeywords
     */
    private function __construct(
        public ?string $terminator,
        public ?array $block,
        public array $blockKeywords,
    ) {}

    /**
     * The syntax for a grammar, or null when the language has no statement to
     * complete — which is most of them, and a decision rather than a gap.
     */
    public static function forGrammar(?string $grammar): ?self
    {
        if (null === $grammar) {
            return null;
        }

        [$terminator, $block, $keywords] = self::SYNTAX[$grammar] ?? [null, null, []];
        if (null === $terminator && null === $block) {
            return null;
        }

        return new self($terminator, $block, $keywords);
    }

    /**
     * Whether $line's first word opens a block in this language.
     *
     * The FIRST word only. `$x = fn() => 1` mentions no keyword at its head and
     * must not gain a brace because one appears later in it, and `iffy(` starts
     * with the letters of `if` without being one — so the match is on a word
     * boundary at position zero, not `str_contains`.
     */
    public function opensABlock(string $line): bool
    {
        $head = [];
        if (1 !== preg_match('/^[A-Za-z_]+/', ltrim($line), $head)) {
            return false;
        }

        // strtolower, not the mb_ form: the pattern above admits ASCII letters
        // only, so the head cannot contain anything the multibyte fold would
        // treat differently — and reaching for mb_ here would be a guard
        // against an input this function cannot receive.
        return \in_array(strtolower($head[0]), $this->blockKeywords, strict: true);
    }
}
