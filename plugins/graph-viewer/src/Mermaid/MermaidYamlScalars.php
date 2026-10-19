<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Preserve JSON-schema timestamp strings before Symfony YAML's date resolution. */
final class MermaidYamlScalars
{
    /** @param non-negative-int|null $rootIndent */
    public static function block(string $body, ?int $rootIndent = null): string
    {
        $body = \ltrim($body, characters: "\r\n");
        $indent = [];
        \preg_match('/^[\t ]*/', $body, $indent);

        $prefix = null === $rootIndent ? $indent[0] : \str_repeat(' ', $rootIndent);

        return (\preg_replace('/^'.\preg_quote($prefix, delimiter: '/').'/m', replacement: '', subject: $body) ?? $body)."\n";
    }

    public static function preserveDates(string $yaml): string
    {
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $value = false;
        $last = '';
        $parts = [];
        $copied = 0;
        $length = \strlen($yaml);
        for ($offset = 0; $offset < $length; ++$offset) {
            $char = $yaml[$offset];
            $previous = $last;
            if (!\ctype_space($char)) {
                $last = $char;
            }
            $structural = $text->advance($char);
            if (!$structural) {
                continue;
            }
            if (':' === $char) {
                $value = \ctype_space($yaml[$offset + 1] ?? '') || '"' === $previous || "'" === $previous;
                continue;
            }
            if (\str_contains(',{}[]', $char)) {
                $value = false;
                continue;
            }
            if (!$value || \ctype_space($char)) {
                continue;
            }
            if (1 === \preg_match(self::DATE, $yaml, offset: $offset)) {
                $parts[] = \substr($yaml, $copied, $offset - $copied).'!!str ';
                $copied = $offset;
            }
            $value = false;
        }
        $parts[] = \substr($yaml, $copied);

        return \implode('', $parts);
    }

    // Date resolution is lexical: even invalid calendar dates are text in the pinned JSON schema.
    private const string DATE = <<<'REGEX'
        /\G[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}
        (?:(?:[Tt]|[\t ]+)[0-9]{1,2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]*)?
        (?:[\t ]*(?:Z|[-+][0-9]{1,2}(?::[0-9]{2})?))?)?
        (?=[\t ]*(?:[,}\]\r\n\#]|$))/x
        REGEX;
}
