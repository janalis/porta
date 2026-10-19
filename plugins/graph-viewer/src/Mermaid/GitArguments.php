<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** Each command has its own closed option set; repeated tags preserve authored order. */
final class GitArguments
{
    /** @return array<string, list<string>> */
    public static function parse(string $command, string $text, MermaidStatement $statement): array
    {
        $result = [];
        $offset = 0;
        while ($offset < \strlen($text)) {
            $match = [];
            if (1 !== \preg_match('~\G\s*(?:(id|msg|tag|type|parent|order):\s*)?('.GitLexer::STRING.'|'.GitLexer::REFERENCE.')~s', $text, $match, offset: $offset)) {
                throw $statement->error('Invalid Git graph argument', \strlen($statement->text) - \strlen($text) + $offset);
            }
            $offset += \strlen($match[0]);
            $raw = $match[2];
            $quoted = \str_starts_with($raw, '"') || \str_starts_with($raw, "'");
            $default = 'commit' === $command ? 'msg' : 'branch';
            $key = '' !== $match[1] ? $match[1] : $default;
            self::validate($command, $key, $raw, $quoted, $result, $statement);
            $value = $quoted ? MermaidQuotedString::decode($raw) : $raw;
            if ('tag' === $key) {
                $result[$key][] = $value;
                continue;
            }
            $result[$key] = [$value];
        }

        return $result;
    }

    /** @param array<string, list<string>> $seen */
    private static function validate(string $command, string $key, string $raw, bool $quoted, array $seen, MermaidStatement $statement): void
    {
        $allowed = match ($command) {
            'commit' => ['id', 'msg', 'tag', 'type'],
            'branch' => ['branch', 'order'],
            'merge' => ['branch', 'id', 'tag', 'type'],
            'cherry-pick' => ['id', 'tag', 'parent'],
            default => ['branch'],
        };
        if (('branch' === $key && [] !== $seen) || ('order' === $key && \array_key_exists('order', $seen)) || !\in_array($key, $allowed, strict: true)) {
            throw $statement->error('Unexpected Git graph option '.$key);
        }
        $valid = match ($key) {
            'branch' => true,
            'type' => !$quoted && \in_array($raw, ['NORMAL', 'REVERSE', 'HIGHLIGHT'], strict: true),
            'order' => !$quoted && 1 === \preg_match('/^(?:0|[1-9]\d*)$/D', $raw) && \strlen($raw) <= 15,
            default => $quoted,
        };
        if (!$valid) {
            throw $statement->error('Invalid Git graph '.$key.' value');
        }
    }
}
