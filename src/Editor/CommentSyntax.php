<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * How one language spells a comment.
 *
 * This exists because the question used to be answered by a fourteen-arm match
 * returning a single prefix string, and the shape of that answer was the bug:
 * a language whose ONLY comment form is a block had nothing to return, so
 * `Ctrl+/` was a dead key in thirty-one of the forty-five grammars
 * {@see \Porta\Support\LanguageMap::grammars()} can produce — Twig, HTML,
 * Markdown, XML, Blade, Vue, Svelte and OCaml among them. CSS was worse than
 * dead: it answered `//`, which is not valid CSS at all.
 *
 * So a language has up to TWO forms and may have either, both or neither, and
 * the two verbs prefer opposite ones:
 *
 * - {@see \Porta\Editor\EditorActions} `toggle_comment` prefers the line form
 *   and falls back to wrapping each line in the block pair;
 * - `toggle_block_comment` prefers the block pair and falls back to the line
 *   form.
 *
 * Neither is ever a no-op in a language that can be commented at all, which is
 * the whole point: the fallbacks are what make one key work everywhere rather
 * than in the subset that happens to spell comments the way C does.
 *
 * The three named constructors exist so the block pair cannot be half-set at a
 * CALL SITE, the same way the table's nested shape stops one being half-written
 * there. An opener without a closer is not a state this class can hold from
 * either direction.
 */
final class CommentSyntax
{
    /**
     * grammar => [line form, block pair]
     *
     * Every name {@see \Porta\Support\LanguageMap::grammars()} produces is a
     * key here, including the two that legitimately have NO comment syntax —
     * `[null, null, null]` is a decision, absence is an oversight, and
     * {@see \Porta\Tests\Unit\Editor\CommentSyntaxTest} can only tell them
     * apart if the decision is written down.
     *
     * Choices worth defending:
     *
     * - **`css` is block-only.** `//` is a SCSS extension; in plain CSS it
     *   silently breaks the rule that follows it. This is the one entry that
     *   CHANGES existing behaviour rather than adding to it.
     * - **`ini` keeps `#`** even though `;` is the classic spelling: the `ini`
     *   grammar is also what `.neon` resolves to, and NEON comments with `#`.
     * - **`ruby` is line-only.** `=begin`/`=end` must each sit alone in column
     *   zero, so they are not a pair that can wrap a range.
     * - **`zig` is line-only** by language design — it has no block form.
     *
     * The pair is NESTED rather than two more columns so that half a pair is
     * not a shape the table can hold — `['<!--', null]` is a plausible typo,
     * and as three flat columns it would have downgraded a language to "no
     * block form" silently rather than failing anywhere.
     *
     * @var array<string, array{0: string|null, 1: array{string, string}|null}>
     */
    private const array SYNTAX = [
        'bash' => ['#', null],
        'blade' => [null, ['{{--', '--}}']],
        'c' => ['//', ['/*', '*/']],
        'clojure' => [';', null],
        'cpp' => ['//', ['/*', '*/']],
        'csharp' => ['//', ['/*', '*/']],
        'css' => [null, ['/*', '*/']],
        'dart' => ['//', ['/*', '*/']],
        'diff' => [null, null],
        'dockerfile' => ['#', null],
        'dotenv' => ['#', null],
        'elixir' => ['#', null],
        'erlang' => ['%', null],
        'go' => ['//', ['/*', '*/']],
        'graphql' => ['#', null],
        'haskell' => ['--', ['{-', '-}']],
        'html' => [null, ['<!--', '-->']],
        'ini' => ['#', null],
        'java' => ['//', ['/*', '*/']],
        'javascript' => ['//', ['/*', '*/']],
        'json' => [null, null],
        'jsonc' => ['//', ['/*', '*/']],
        'julia' => ['#', ['#=', '=#']],
        'kotlin' => ['//', ['/*', '*/']],
        'lua' => ['--', ['--[[', ']]']],
        'markdown' => [null, ['<!--', '-->']],
        'nim' => ['#', ['#[', ']#']],
        'ocaml' => [null, ['(*', '*)']],
        'php' => ['//', ['/*', '*/']],
        'python' => ['#', null],
        'ruby' => ['#', null],
        'rust' => ['//', ['/*', '*/']],
        'scala' => ['//', ['/*', '*/']],
        'scss' => ['//', ['/*', '*/']],
        'sql' => ['--', ['/*', '*/']],
        'svelte' => [null, ['<!--', '-->']],
        'swift' => ['//', ['/*', '*/']],
        'terraform' => ['#', ['/*', '*/']],
        'toml' => ['#', null],
        'twig' => [null, ['{#', '#}']],
        'typescript' => ['//', ['/*', '*/']],
        'vue' => [null, ['<!--', '-->']],
        'xml' => [null, ['<!--', '-->']],
        'yaml' => ['#', null],
        'zig' => ['//', null],
    ];

    /**
     * The block pair is ONE nullable property rather than two, so an opener
     * without a closer is not a state this class can hold — every consumer
     * destructures it after a single null check instead of testing two fields
     * that are always set together.
     *
     * @param array{string, string}|null $block
     */
    private function __construct(
        public ?string $line,
        public ?array $block,
    ) {}

    /**
     * The syntax for a highlighting grammar, or null when the language has no
     * comment form at all — `json` forbids them (that is what `jsonc` is for)
     * and `diff` is a generated format. Null is also the answer for a grammar
     * nothing claims, which is how a plain-text buffer stays untouched.
     */
    public static function forGrammar(?string $grammar): ?self
    {
        if (null === $grammar) {
            return null;
        }

        [$line, $block] = self::SYNTAX[$grammar] ?? [null, null];

        return null === $line && null === $block ? null : new self($line, $block);
    }

    public static function lineOnly(string $line): self
    {
        return new self($line, block: null);
    }

    public static function blockOnly(string $open, string $close): self
    {
        return new self(line: null, block: [$open, $close]);
    }

    public static function both(string $line, string $open, string $close): self
    {
        return new self($line, [$open, $close]);
    }
}
