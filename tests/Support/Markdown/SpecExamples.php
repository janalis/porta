<?php

declare(strict_types=1);

namespace Porta\Tests\Support\Markdown;

/**
 * The examples of a CommonMark-format spec file (the CommonMark spec, GFM's):
 * each fenced `example` block's markdown and expected HTML, with `→` standing
 * for a tab, keyed by number and section.
 */
final class SpecExamples
{
    private const string FENCE = '````````````````````````````````';

    /**
     * @param list<string>|null $only the example tags to keep (`example table`
     *                                → `table`), or null for plain examples only
     *
     * @return array<string, array{string, string, string}> "#n section" => [markdown, html, section]
     */
    public static function read(string $file, ?array $only = null): array
    {
        $lines = \file($file, \FILE_IGNORE_NEW_LINES);
        $lines = false === $lines ? [] : \array_values($lines);
        $examples = [];
        $section = '';
        $number = 0;
        for ($i = 0, $count = \count($lines); $i < $count; ++$i) {
            $line = $lines[$i];
            if (\str_starts_with($line, '#')) {
                $section = \trim($line, characters: "# \t");
                continue;
            }
            if (!\str_starts_with($line, self::FENCE.' example')) {
                continue;
            }
            ++$number;
            $tag = \trim(\substr($line, \strlen(self::FENCE.' example')));
            [$markdown, $html, $i] = self::body($lines, $i + 1);
            $keep = null === $only ? '' === $tag : \in_array($tag, $only, strict: true);
            if ($keep) {
                $examples[\sprintf('#%d %s', $number, $section)] = [$markdown, $html, $section];
            }
        }

        return $examples;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{string, string, int}
     */
    private static function body(array $lines, int $i): array
    {
        $markdown = '';
        while ('.' !== $lines[$i]) {
            $markdown .= $lines[$i++]."\n";
        }
        ++$i;
        $html = '';
        while (self::FENCE !== $lines[$i]) {
            $html .= $lines[$i++]."\n";
        }

        return [\str_replace('→', replace: "\t", subject: $markdown), \str_replace('→', replace: "\t", subject: $html), $i];
    }
}
