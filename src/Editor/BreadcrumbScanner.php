<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Extracts the structural scope path (namespace / class / interface / trait /
 * enum / function) from PHP source with the built-in lexer, for the breadcrumbs
 * bar. Pure and synchronous — no LSP dependency, so it works offline and is
 * deterministic to test. Anonymous functions and `Foo::class` / `new class`
 * never produce crumbs but keep the brace nesting correct.
 */
final class BreadcrumbScanner
{
    /**
     * {@see \Porta\Highlight\CodeHighlighter}'s MAX_HIGHLIGHT_BYTES twin: past
     * this, no crumbs and no sticky headers rather than a tokenizer pass whose
     * cost scales with the file on every rescan.
     */
    public const int MAX_SCAN_BYTES = 512 * 1024;

    /**
     * All named scopes in the source, as 1-based line spans. `kind` is
     * `namespace` / `type` (class/interface/trait/enum) / `function`.
     *
     * @return list<array{label: string, startLine: int, endLine: int, kind: string}>
     */
    public static function scan(string $source): array
    {
        if (\strlen($source) > self::MAX_SCAN_BYTES) {
            return [];
        }
        // Editing buffers are often mid-edit (unbalanced braces, partial
        // syntax): swallow the tokenizer's warnings, keeping the tokens it
        // still returns.
        \set_error_handler(static fn (): bool => true);
        /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
        $tokens = \token_get_all($source);
        \restore_error_handler();

        /** @var list<array{label: string, startLine: int, endLine: int, kind: string}> $scopes */
        $scopes = [];
        /** @var list<array{label: string, startLine: int, kind: string}> $namespaces */
        $namespaces = [];
        /** @var list<array{label: string, startLine: int, kind: string}|null> $stack open scopes (null = anonymous block) */
        $stack = [];
        /** @var array{label: string, startLine: int, kind: string}|null $pending a captured decl name awaiting its `{` */
        $pending = null;
        /** @var array{label: string, startLine: int, kind: string}|null $nsPending a namespace name being collected */
        $nsPending = null;
        /** @var 'type'|'function'|null $declKind armed for exactly the next name token */
        $declKind = null;
        $prev = null; // previous significant token id (int) or single char (string)
        $maxLine = 1;

        foreach ($tokens as $token) {
            if (\is_array($token)) {
                [$id, $text, $tokLine] = $token;
                $maxLine = \max($maxLine, $tokLine + \substr_count($text, needle: "\n"));

                if (\T_WHITESPACE === $id || \T_COMMENT === $id || \T_DOC_COMMENT === $id) {
                    continue; // insignificant: leave $prev/$declKind untouched
                }

                if (null !== $nsPending && (\T_STRING === $id || \T_NS_SEPARATOR === $id || \T_NAME_QUALIFIED === $id || \T_NAME_FULLY_QUALIFIED === $id)) {
                    $nsPending['label'] .= $text;
                    $prev = $id;
                    continue;
                }

                if (null !== $declKind) {
                    if (\T_STRING === $id) {
                        $pending = ['label' => $text, 'startLine' => $tokLine, 'kind' => $declKind];
                    }
                    $declKind = null; // consumed (armed for a single significant token only)
                }

                if (\T_NAMESPACE === $id) {
                    $nsPending = ['label' => '', 'startLine' => $tokLine, 'kind' => 'namespace'];
                }
                if (\T_CLASS === $id && \T_DOUBLE_COLON !== $prev && \T_NEW !== $prev) {
                    $declKind = 'type';
                }
                if (\T_INTERFACE === $id || \T_TRAIT === $id || \T_ENUM === $id) {
                    $declKind = 'type';
                }
                if (\T_FUNCTION === $id && \T_USE !== $prev) {
                    $declKind = 'function';
                }

                $prev = $id;
                continue;
            }

            // Single-character token ({ } ; ( …): no line number of its own.
            $declKind = null; // a decl name is an array token; a symbol here means anonymous
            $prev = $token;
            // @mago-expect lint:no-insecure-comparison -- $token is a lexer token, not sensitive data
            if ('{' === $token) {
                if (null !== $nsPending && '' !== $nsPending['label']) {
                    $stack[] = $nsPending;
                    $nsPending = null;
                    continue;
                }
                if (null !== $pending) {
                    $stack[] = $pending;
                    $pending = null;
                    continue;
                }
                $stack[] = null;
                continue;
            }
            // @mago-expect lint:no-insecure-comparison -- $token is a lexer token, not sensitive data
            if ('}' === $token) {
                $top = \array_pop($stack);
                if (\is_array($top)) {
                    $scopes[] = ['label' => $top['label'], 'startLine' => $top['startLine'], 'endLine' => $maxLine, 'kind' => $top['kind']];
                }
                continue;
            }
            // @mago-expect lint:no-insecure-comparison -- $token is a lexer token, not sensitive data
            if (';' === $token) {
                if (null !== $nsPending && '' !== $nsPending['label']) {
                    $namespaces[] = $nsPending;
                }
                $nsPending = null;
                $pending = null; // an abstract/interface method: a decl with no body
            }
        }

        // Scopes left open by mid-edit unclosed braces close at end-of-file.
        while ([] !== $stack) {
            $top = \array_pop($stack);
            if (\is_array($top)) {
                $scopes[] = ['label' => $top['label'], 'startLine' => $top['startLine'], 'endLine' => $maxLine, 'kind' => $top['kind']];
            }
        }
        // A `;`-form namespace is active until the next namespace, or EOF.
        foreach ($namespaces as $i => $ns) {
            $scopes[] = [
                'label' => $ns['label'],
                'startLine' => $ns['startLine'],
                'endLine' => \array_key_exists($i + 1, $namespaces) ? $namespaces[$i + 1]['startLine'] - 1 : $maxLine,
                'kind' => 'namespace',
            ];
        }

        \usort($scopes, static fn (array $a, array $b): int => ($cmp = $a['startLine'] <=> $b['startLine']) ? $cmp : $b['endLine'] <=> $a['endLine']);

        return \array_values($scopes);
    }

    /**
     * The enclosing scopes at a 0-based caret line, outermost first, each mapped
     * to a 0-based jump line (the scope's declaration line).
     *
     * @param list<array{label: string, startLine: int, endLine: int, kind: string}> $scopes
     *
     * @return list<array{label: string, line: int}>
     */
    public static function select(array $scopes, int $caretLine): array
    {
        $line = $caretLine + 1; // scopes are 1-based (token space)
        $trail = [];
        foreach ($scopes as $scope) {
            if ($scope['startLine'] > $line || $line > $scope['endLine']) {
                continue;
            }

            $trail[] = ['label' => $scope['label'], 'line' => $scope['startLine'] - 1];
        }

        return $trail;
    }

    /**
     * Sticky-scroll headers for a viewport whose top (0-based) doc line is
     * $topLine: the enclosing non-namespace scopes whose declaration line has
     * scrolled off the top, outermost-first, capped at $max. Each maps to its
     * 0-based declaration line.
     *
     * @param list<array{label: string, startLine: int, endLine: int, kind: string}> $scopes
     *
     * @return list<array{label: string, line: int}>
     */
    public static function stickyHeaders(array $scopes, int $topLine, int $max): array
    {
        $line = $topLine + 1; // scopes are 1-based
        $headers = [];
        foreach ($scopes as $scope) {
            $header = $scope['startLine'] - 1; // 0-based declaration line
            if ('namespace' !== $scope['kind'] && $scope['startLine'] <= $line && $line <= $scope['endLine'] && $header < $topLine) {
                $headers[] = ['label' => $scope['label'], 'line' => $header];
            }
        }

        return \array_slice($headers, offset: 0, length: \max(0, $max));
    }
}
