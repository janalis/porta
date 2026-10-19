<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Entity escapes are decoded after lexical separators, retaining literal punctuation. */
final class MermaidText
{
    /** @return array{float, float} */
    public static function box(string $text, float $scale = 1.0): array
    {
        $lines = \explode("\n", $text);

        $width = (float) \max(6, \min(120, \max(\array_map(AnsiUtils::visibleWidth(...), $lines)) + 4));

        return [(($width - 4) * $scale) + 4, (2 * \count($lines) * $scale) + 4];
    }

    public static function decode(string $text): string
    {
        $text = \preg_replace('/<br\s*\/?\s*>/i', replacement: "\n", subject: $text) ?? $text;
        $text =
            \preg_replace_callback(
                '/#(\d+);/',
                static function (array $match): string {
                    $character = \mb_chr((int) $match[1]);

                    return false === $character ? $match[0] : $character;
                },
                $text,
            ) ?? $text;
        $text =
            \preg_replace_callback(
                '/#([a-z]+);/i',
                static function (array $match): string {
                    $entity = '&'.$match[1].';';
                    $decoded = \html_entity_decode($entity, flags: \ENT_QUOTES | \ENT_HTML5, encoding: 'UTF-8');

                    return $decoded === $entity ? $match[0] : $decoded;
                },
                $text,
            ) ?? $text;

        return MermaidUtf16::display(\html_entity_decode($text, flags: \ENT_QUOTES | \ENT_HTML5, encoding: 'UTF-8'));
    }
}
