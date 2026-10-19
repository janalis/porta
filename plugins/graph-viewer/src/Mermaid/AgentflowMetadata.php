<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Bounded descriptive YAML mappings; executable object tags and aliases are rejected. */
final class AgentflowMetadata
{
    /** @return array<array-key, mixed> */
    public static function parse(MermaidStatement $statement): array
    {
        $body = \substr($statement->text, offset: 2, length: -1);
        $multiline = \str_contains($body, "\n");
        $yaml = $multiline ? MermaidYamlScalars::block($body) : "{\n".$body."\n}";
        try {
            return self::mapping($yaml, $statement);
        } catch (ParseException $error) {
            if ($multiline) {
                $retry = self::withoutTrailingCommas($yaml);
                if ($retry !== $yaml) {
                    try {
                        return self::mapping($retry, $statement);

                        // @mago-expect lint:no-empty-catch-clause -- The first parse error is reported after this optional retry.
                    } catch (ParseException) {
                        // Preserve the original diagnostic when the compatibility retry fails.
                    }
                }
            }
            throw $statement->error('Invalid agentflow metadata: '.$error->getMessage());
        }
    }

    private static function withoutTrailingCommas(string $yaml): string
    {
        $text = new MermaidConfigurationText();
        $text->advance("\n");
        $depth = 0;
        $copied = 0;
        $parts = [];
        $length = \strlen($yaml);
        for ($offset = 0; $offset < $length; ++$offset) {
            $char = $yaml[$offset];
            if (!$text->advance($char)) {
                continue;
            }
            if ('[' === $char || '{' === $char) {
                ++$depth;
            }
            if (']' === $char || '}' === $char) {
                --$depth;
            }
            if (0 === $depth && ',' === $char && 1 === \preg_match('/\G,[\t ]*(?:#[^\n]*)?(?=\n|$)/', $yaml, offset: $offset)) {
                $parts[] = \substr($yaml, $copied, $offset - $copied);
                $copied = $offset + 1;
            }
        }
        $parts[] = \substr($yaml, $copied);

        return \implode('', $parts);
    }

    /** @return array<array-key, mixed> */
    private static function mapping(string $yaml, MermaidStatement $statement): array
    {
        // @mago-expect analysis:mixed-assignment -- YAML is narrowed to a mapping below.
        $value = Yaml::parse(MermaidYamlScalars::preserveDates($yaml), flags: Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS);
        if (null === $value) {
            return [];
        }
        if (!$value instanceof \stdClass) {
            throw $statement->error('Agentflow metadata must be a mapping');
        }
        $budget = MermaidGraph::MAX_ITEMS;

        return self::sanitize(\get_object_vars($value), $statement, $budget);
    }

    /** @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function sanitize(array $values, MermaidStatement $statement, int &$budget): array
    {
        $result = [];
        // @mago-expect analysis:mixed-assignment -- Recursive YAML values are narrowed before traversing them.
        foreach ($values as $key => $value) {
            if (--$budget < 0) {
                throw $statement->error('Agentflow metadata exceeds the item limit');
            }
            if (\in_array($key, ['__proto__', 'constructor', 'prototype'], strict: true)) {
                continue;
            }
            if ($value instanceof \stdClass) {
                $value = \get_object_vars($value);
            }
            $result[$key] = \is_array($value) ? self::sanitize($value, $statement, $budget) : $value;
        }

        return $result;
    }
}
