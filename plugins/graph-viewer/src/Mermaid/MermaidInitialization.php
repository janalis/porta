<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Mermaid\Theme\JsObject;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Directives merge in source order wherever they stand, as pinned `detectInit` finds them;
 * masking never shifts the diagram around them.
 */
final readonly class MermaidInitialization
{
    private function __construct(
        public string $source,
        public MermaidConfiguration $configuration,
    ) {}

    /** @return \Generator<int, null, mixed, self> */
    public static function parse(string $source, MermaidConfiguration $configuration = new MermaidConfiguration()): \Generator
    {
        MermaidSource::validate($source);
        $length = \strlen($source);
        $offset = 0;
        $consumed = 0;
        $parts = [];
        $count = 0;
        $located = 0;
        $line = 0;
        $column = 0;
        while ($offset < $length) {
            $skip = [];
            if (1 === \preg_match('/\G(?:\s+|%%(?!\{)[^\r\n]*)/u', $source, $skip, offset: $offset)) {
                $offset += \strlen($skip[0]);
                continue;
            }
            if ('%%{' !== \substr($source, $offset, length: 3)) {
                $next = \strpos($source, needle: '%%{', offset: $offset);
                if (false === $next) {
                    break;
                }
                $offset = $next;
            }
            $prefix = \substr($source, $located, $offset - $located);
            $last = \strrpos($prefix, needle: "\n");
            $column = false === $last ? $column + \mb_strlen($prefix) : \mb_strlen(\substr($prefix, $last + 1));
            $line += \substr_count($prefix, needle: "\n");
            $located = $offset;
            $statement = new MermaidStatement('', $offset, $line, $column);
            if (++$count > MermaidGraph::MAX_ITEMS) {
                throw $statement->error('Diagram exceeds the initialization directive limit');
            }
            $end = self::end($source, $offset, $statement);
            $text = \substr($source, $offset + 3, $end - $offset - 6);
            $configuration = $configuration->overlay(self::configuration($text, $statement));
            $parts[] = \substr($source, $consumed, $offset - $consumed);
            $parts[] = \preg_replace('/[^\r\n]/', replacement: ' ', subject: \substr($source, $offset, $end - $offset)) ?? '';
            $consumed = $end;
            $offset = $end;
            yield null;
        }
        $parts[] = \substr($source, $consumed);

        return new self(\implode('', $parts), $configuration);
    }

    private static function end(string $source, int $start, MermaidStatement $statement): int
    {
        $text = new MermaidConfigurationText();
        $depth = 0;
        $length = \strlen($source);
        for ($offset = $start + 3; $offset < $length; ++$offset) {
            if (($offset - $start + 3) > MermaidSource::MAX_STATEMENT) {
                throw $statement->error('Initialization directive exceeds the size limit');
            }
            if (!$text->advance($source[$offset])) {
                continue;
            }
            if (0 === $depth && '}%%' === \substr($source, $offset, length: 3)) {
                return $offset + 3;
            }
            $depth += match ($source[$offset]) {
                '{', '[' => 1,
                '}', ']' => -1,
                default => 0,
            };
            if ($depth < 0 || $depth > 128) {
                throw $statement->error('Invalid initialization nesting or depth limit');
            }
        }
        throw $statement->error('Unclosed Mermaid initialization directive');
    }

    private static function configuration(string $text, MermaidStatement $statement): MermaidConfiguration
    {
        // Pinned `%%{wrap}%%` is shorthand for wrapping every label.
        $text = 1 === \preg_match('/^\s*wrap\s*$/', $text) ? 'init: {wrap: true}' : $text;
        try {
            // @mago-expect analysis:mixed-assignment -- YAML is narrowed to the directive wrapper below.
            $object = Yaml::parse('{'.$text.'}', flags: Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS);
        } catch (ParseException) {
            throw $statement->error('Invalid Mermaid initialization configuration');
        }
        if (!$object instanceof \stdClass || 1 !== \count(\get_object_vars($object))) {
            throw $statement->error('Expected one init or initialize configuration');
        }
        // @mago-expect analysis:mixed-assignment -- The wrapper value is narrowed to a configuration map below.
        $config = $object->init ?? $object->initialize ?? null;
        if (!$config instanceof \stdClass) {
            throw $statement->error('Expected init or initialize with a configuration mapping');
        }
        // Pinned directives read a nested `config` map as root options, under the directive's own.
        // @mago-expect analysis:mixed-assignment -- Narrowed to a mapping below.
        $nested = JsObject::get($config, 'config');
        if ($nested instanceof \stdClass) {
            $config = (object) [...JsObject::values($nested), ...JsObject::values($config)];
        }

        return MermaidConfiguration::parse($config, $statement);
    }
}
