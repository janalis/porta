<?php

declare(strict_types=1);

namespace Porta\Config\EditorConfig;

/**
 * Parses a single `.editorconfig` file (an INI-like format) into its `root`
 * preamble flag and its ordered list of `[glob]` sections. Property keys are
 * lowercased; values are trimmed but kept case-sensitive (globs, charsets and
 * numbers are matched/coerced later by {@see ResolvedEditorConfig}). Comments
 * (`#` / `;`), blank lines and malformed lines are ignored.
 */
final class EditorConfigParser
{
    /**
     * @return array{root: bool, sections: list<array{glob: string, props: array<string, string>}>}
     */
    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $root = false;
        $sections = [];
        $current = null; // index into $sections, or null while still in the preamble

        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || '#' === $trimmed[0] || ';' === $trimmed[0]) {
                continue;
            }

            if ('[' === $trimmed[0] && str_ends_with($trimmed, ']')) {
                $sections[] = ['glob' => substr($trimmed, 1, -1), 'props' => []];
                $current = \count($sections) - 1;
                continue;
            }

            $eq = strpos($trimmed, '=');
            if (false === $eq) {
                continue;
            }
            $key = strtolower(trim(substr($trimmed, 0, $eq)));
            $value = trim(substr($trimmed, $eq + 1));
            if ('' === $key) {
                continue;
            }

            if (null === $current) {
                if ('root' === $key) {
                    $root = 'true' === strtolower($value);
                }
                continue;
            }

            $sections[$current]['props'][$key] = $value;
        }

        return ['root' => $root, 'sections' => $sections];
    }
}
