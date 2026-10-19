<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Declaration-local document directives from the pinned js-yaml JSON schema. */
final class SequenceYamlDirectives
{
    /** @return array{string, array<string, string>} source retaining line breaks, declared tag prefixes */
    public static function read(string $yaml, MermaidStatement $statement): array
    {
        if (!\str_contains($yaml, '%')) {
            return [$yaml, []];
        }
        $handles = [];
        $version = false;
        $directives = false;
        $offset = 0;
        $masked = [];
        $length = \strlen($yaml);
        while ($offset < $length) {
            $end = \strpos($yaml, needle: "\n", offset: $offset);
            $end = false === $end ? $length : $end + 1;
            $line = \substr($yaml, $offset, $end - $offset);
            if ('' === \trim($line) || \str_starts_with(\ltrim($line), '#')) {
                $masked[] = $line;
                $offset = $end;
                continue;
            }
            if (!\str_starts_with($line, '%')) {
                break;
            }
            $directives = true;
            $tokens = \preg_split('/[\t \r\n]+/', \trim($line));
            /** @var non-empty-list<string> $tokens The line begins with a directive indicator. */
            $name = \array_shift($tokens);
            $comment = \array_find_key($tokens, static fn (string $word): bool => \str_starts_with($word, '#'));
            if (null !== $comment) {
                $tokens = \array_slice($tokens, offset: 0, length: $comment);
            }
            if ('%' === $name) {
                throw $statement->error('Invalid sequence participant YAML configuration');
            }
            if ('%YAML' === $name) {
                if ($version || 1 !== \count($tokens) || 1 !== \preg_match('/^0*1\.\d+$/D', $tokens[0])) {
                    throw $statement->error('Invalid sequence participant YAML configuration');
                }
                $version = true;
            }
            if ('%TAG' === $name) {
                if (2 !== \count($tokens) || \array_key_exists($tokens[0], $handles) || 1 !== \preg_match('/^(?:!|!!|![a-z\-]+!)$/iD', $tokens[0])) {
                    throw $statement->error('Invalid sequence participant YAML configuration');
                }
                $prefix = $tokens[1];
                if (1 !== \preg_match("~^(?:!|[^,\\[\\]{}])(?:%[0-9a-f]{2}|[0-9a-z\\-\\#;/?:@&=+\\$,_.!\\~*'()\\[\\]])*$~iD", $prefix) || 1 === \preg_match('/%(?![0-9a-f]{2})/i', $prefix)) {
                    throw $statement->error('Invalid sequence participant YAML configuration');
                }
                $prefix = \rawurldecode($prefix);
                if (1 !== \preg_match('//u', $prefix)) {
                    throw $statement->error('Invalid sequence participant YAML configuration');
                }
                $handles[$tokens[0]] = $prefix;
            }
            // Unknown directives only warn in js-yaml; they have no graphical effect.
            $masked[] = \preg_replace('/[^\r\n]/', replacement: ' ', subject: $line) ?? $line;
            $offset = $end;
        }
        if ($directives && !\str_starts_with(\substr($yaml, $offset), '---')) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }

        return [\implode('', $masked).\substr($yaml, $offset), $handles];
    }
}
