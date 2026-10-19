<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\Support\AnsiText;

/** Native sequence labels wrap at 60 display columns, preserving authored line breaks. */
final class SequenceText
{
    public static function parse(string $text, MermaidConfiguration $configuration): string
    {
        [$text, $wrap] = self::description($text);

        return self::decoded(MermaidText::decode($text), $wrap ?? $configuration->wrapping());
    }

    /** Metadata replaces an absent description or one spelling the actor's raw name. */
    public static function participant(string $id, ?string $alias, ?string $description, MermaidConfiguration $configuration): ?string
    {
        if (null === $description) {
            return null === $alias ? null : self::decoded($alias, $configuration->wrapping());
        }
        [$text, $wrap] = self::description($description);

        return self::decoded(null !== $alias && self::usesAlias($id, $description) ? $alias : MermaidText::decode($text), $wrap ?? $configuration->wrapping());
    }

    public static function usesAlias(string $id, ?string $description): bool
    {
        return null === $description || self::description($description)[0] === $id;
    }

    /** @return array{string, ?bool} raw text and explicit wrapping override */
    private static function description(string $text): array
    {
        $text = \trim($text);
        $match = [];
        $wrap = null;
        if (1 === \preg_match('/^:?(nowrap|wrap):/i', $text, $match)) {
            $wrap = 'wrap' === \strtolower($match[1]);
            $text = \trim(\substr($text, \strlen($match[0])));
        }

        return [$text, $wrap];
    }

    public static function decoded(string $text, ?bool $wrap): string
    {
        if (true !== $wrap) {
            return $text;
        }
        $lines = [];
        foreach (\explode("\n", $text) as $line) {
            \array_push($lines, ...AnsiText::wrap($line, width: 60));
        }

        return \implode("\n", $lines);
    }
}
