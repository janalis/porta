<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Generated IDs follow JavaScript's ASCII word test over UTF-16 code units. */
final class UsecaseLabels
{
    public const string QUOTED = '"`[\s\S]*?`"|"[^"\r\n]*"|\'[^\'\r\n]*\'';
    public const string IDENTIFIER = '[A-Za-z0-9_]+';

    public static function generated(string $label): string
    {
        // @noinspection NotOptimalRegularExpressionsInspection -- JavaScript word IDs are ASCII even for Unicode labels.
        return \preg_replace_callback('/[^A-Za-z0-9_]/u', static fn (array $part): string => \mb_ord($part[0]) > 0xFFFF ? '__' : '_', $label) ?? $label;
    }

    public static function identifier(string $id, MermaidStatement $statement): string
    {
        if (
            1 === \preg_match('/^(?:usecase-beta|actor|systemBoundary|end|direction|TD|TB|BT|LR|RL|note|for|classDef|class|style|true|false)$/', $id)
            || 1 === \preg_match('/^(?:include|extend)$/i', $id)
        ) {
            throw $statement->error('Reserved use-case identifier '.$id);
        }

        return $id;
    }

    /** @return array{raw: string, label: string, markdown: bool} */
    public static function quoted(string $text): array
    {
        $markdown = \str_starts_with($text, '"`');
        $raw = \substr($text, $markdown ? 2 : 1, $markdown ? -2 : -1);

        return ['raw' => $raw, 'label' => MermaidText::decode($raw), 'markdown' => $markdown];
    }
}
