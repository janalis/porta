<?php

declare(strict_types=1);

namespace Porta\GraphViewer;

/**
 * Token-based extraction of a PHP file's namespace and class imports — the
 * piece the architecture scanner needs and no src class had (the arch-gate
 * tool uses a Porta-specific regex; {@see \Porta\Editor\BreadcrumbScanner}
 * collects namespaces but not uses). Handles qualified names, aliases
 * (`use X as Y` records X), comma lists, grouped imports (`use A\{B, C\D}`),
 * and skips `use function` / `use const` (statement-wide) — while class-body
 * trait `use` and closure `use (...)` never match because only depth-0
 * statements are considered. Braced-namespace files are a documented
 * limitation: their imports sit at depth 1 and are not collected.
 */
final class UseScanner
{
    /** @return array{namespace: ?string, uses: list<string>} */
    public static function scan(string $source): array
    {
        // Files may be mid-edit or malformed: swallow tokenizer warnings,
        // keeping the tokens it still returns (the BreadcrumbScanner idiom).
        set_error_handler(static fn (): bool => true);
        /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
        $tokens = token_get_all($source);
        restore_error_handler();

        $namespace = null;
        $uses = [];
        $depth = 0;
        $collecting = null; // 'namespace' | 'use' | null
        $buffer = '';
        $groupPrefix = null; // inside `use A\{ … }`
        $dropStatement = false; // `use function` / `use const` — applies to every item
        $suppressItem = false; // after `as`: the alias identifier itself

        foreach ($tokens as $token) {
            if (\is_array($token)) {
                [$id, $text] = $token;
                if (\T_WHITESPACE === $id || \T_COMMENT === $id || \T_DOC_COMMENT === $id) {
                    continue;
                }
                if (\T_CURLY_OPEN === $id || \T_DOLLAR_OPEN_CURLY_BRACES === $id) {
                    ++$depth;
                    continue;
                }
                if (0 === $depth && \T_NAMESPACE === $id) {
                    $collecting = 'namespace';
                    $buffer = '';
                    continue;
                }
                if (0 === $depth && \T_USE === $id) {
                    $collecting = 'use';
                    $buffer = '';
                    $groupPrefix = null;
                    $dropStatement = false;
                    $suppressItem = false;
                    continue;
                }
                if ('use' === $collecting && (\T_FUNCTION === $id || \T_CONST === $id)) {
                    $dropStatement = true;
                    continue;
                }
                if ('use' === $collecting && \T_AS === $id) {
                    if (!$dropStatement && !$suppressItem) {
                        self::flush($uses, $buffer, $groupPrefix);
                    }
                    $buffer = '';
                    $suppressItem = true;
                    continue;
                }
                if (null !== $collecting && !$suppressItem && (\T_STRING === $id || \T_NS_SEPARATOR === $id || \T_NAME_QUALIFIED === $id || \T_NAME_FULLY_QUALIFIED === $id)) {
                    $buffer .= $text;
                }
                continue;
            }

            switch ($token) {
                case '{':
                    if ('use' === $collecting && \strlen($buffer) > 0) {
                        $groupPrefix = $buffer; // `use A\{ …`
                        $buffer = '';
                        break;
                    }
                    if ('namespace' === $collecting) {
                        $namespace = '' !== $buffer ? trim($buffer, characters: '\\') : null;
                        $collecting = null;
                    }
                    ++$depth;
                    break;
                case '}':
                    $depth = max(0, $depth - 1);
                    if ('use' === $collecting && null !== $groupPrefix) {
                        if (!$dropStatement && !$suppressItem) {
                            self::flush($uses, $buffer, $groupPrefix);
                        }
                        $buffer = '';
                        $groupPrefix = null;
                        $suppressItem = false;
                    }
                    break;
                case ',':
                    if ('use' === $collecting) {
                        if (!$dropStatement && !$suppressItem) {
                            self::flush($uses, $buffer, $groupPrefix);
                        }
                        $buffer = '';
                        $suppressItem = false;
                    }
                    break;
                case '(':
                    if ('use' === $collecting) {
                        $collecting = null; // closure `use (` — not an import
                        $buffer = '';
                    }
                    break;
                case ';':
                    if ('namespace' === $collecting) {
                        $namespace = '' !== $buffer ? trim($buffer, characters: '\\') : null;
                    }
                    if ('use' === $collecting && !$dropStatement && !$suppressItem) {
                        self::flush($uses, $buffer, $groupPrefix);
                    }
                    $collecting = null;
                    $buffer = '';
                    $groupPrefix = null;
                    $suppressItem = false;
                    break;
                default:
                    break;
            }
        }

        return ['namespace' => $namespace, 'uses' => $uses];
    }

    /** @param list<string> $uses */
    private static function flush(array &$uses, string $buffer, ?string $groupPrefix): void
    {
        if ('' === $buffer) {
            return;
        }
        $name = trim((null !== $groupPrefix ? rtrim($groupPrefix, characters: '\\').'\\' : '').$buffer, characters: '\\');
        if ('' !== $name) {
            $uses[] = $name;
        }
    }
}
