<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Mermaid\Theme\JsObject;
use Porta\GraphViewer\Mermaid\Theme\MermaidMerge;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Bounded leading YAML metadata; masking retains every authored byte and source line. */
final readonly class MermaidFrontMatter
{
    private function __construct(
        public string $source,
        public ?MermaidStatement $title,
        public MermaidConfiguration $configuration = new MermaidConfiguration(),
    ) {}

    public static function parse(string $source): self
    {
        MermaidSource::validate($source);
        $match = [];
        if (1 !== \preg_match('/^([\t ]*)---[\t ]*\r?\n/', $source, $match)) {
            return new self($source, title: null);
        }
        $opener = new MermaidStatement('---', offset: 0, line: 0, column: \strlen($match[1]));
        $start = \strlen($match[0]);
        $cursor = $start;
        while ($cursor < \strlen($source)) {
            $end = \strpos($source, needle: "\n", offset: $cursor);
            $end = false === $end ? \strlen($source) : $end + 1;
            if ($end > MermaidSource::MAX_STATEMENT) {
                throw $opener->error('Front matter exceeds the size limit');
            }
            $line = \substr($source, $cursor, $end - $cursor);
            if ($match[1].'---' === \rtrim($line, characters: "\r\n\t ")) {
                $body = \substr($source, $start, $cursor - $start);
                [$title, $configuration] = self::metadata($body, $match[1], $start, $opener);
                $mask = \preg_replace('/[^\r\n]/', replacement: ' ', subject: \substr($source, offset: 0, length: $end)) ?? '';

                return new self($mask.\substr($source, $end), $title, $configuration);
            }
            $cursor = $end;
        }
        throw $opener->error('Unclosed Mermaid front matter');
    }

    /** @return array{?MermaidStatement, MermaidConfiguration} */
    private static function metadata(string $body, string $indent, int $start, MermaidStatement $opener): array
    {
        $root = [];
        \preg_match('/^([\t ]*)(?=[^\s#])/m', $body, $root);
        $indent = $root[1] ?? $indent;
        $yaml = \preg_replace('/^'.\preg_quote($indent, delimiter: '/').'/m', replacement: '', subject: $body) ?? $body;
        try {
            // @mago-expect analysis:mixed-assignment -- Decoded YAML is narrowed to a map below.
            $object = Yaml::parse($yaml, flags: Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS);
        } catch (ParseException) {
            throw $opener->error('Invalid Mermaid YAML front matter');
        }
        // @mago-expect analysis:mixed-assignment -- Empty YAML is normalized to a map, then validated below.
        $object ??= new \stdClass();
        if (!$object instanceof \stdClass) {
            throw $opener->error('Mermaid front matter must be a mapping');
        }
        $fields = \get_object_vars($object);
        // @mago-expect analysis:mixed-assignment -- Narrowed to text below.
        $display = $fields['displayMode'] ?? null;
        if (null !== $display && !\is_string($display)) {
            throw $opener->error('Mermaid front matter displayMode must be text');
        }
        $configuration = new MermaidConfiguration();
        if (\array_key_exists('config', $fields) && !$fields['config'] instanceof \stdClass) {
            throw $opener->error('Mermaid front matter configuration must be a mapping');
        }
        $config = MermaidMerge::object(JsObject::object($fields['config'] ?? null));
        if ('compact' === $display) {
            // Pinned gantt charts read the front matter mode before `gantt.displayMode`; compact wins either way.
            JsObject::set($config, 'gantt', (object) [...JsObject::values(JsObject::object(JsObject::get($config, 'gantt'))), 'displayMode' => 'compact']);
        }
        if (\array_key_exists('config', $fields) || 'compact' === $display) {
            $configuration = MermaidConfiguration::parse($config, $opener, frontMatter: true);
        }
        // @mago-expect analysis:mixed-assignment -- Title is narrowed to a string below.
        $title = $fields['title'] ?? null;
        if (null !== $title && !\is_string($title)) {
            throw $opener->error('Mermaid front matter title must be a string');
        }
        if (null === $title || '' === \trim($title)) {
            return [null, $configuration];
        }
        $key = [];
        \preg_match('/^'.\preg_quote($indent, delimiter: '/').'(?:title|"title"|\'title\')[\t ]*:/m', $body, $key, flags: \PREG_OFFSET_CAPTURE);
        $offset = (int) ($key[0][1] ?? 0);
        $line = 1 + \substr_count(\substr($body, offset: 0, length: $offset), needle: "\n");

        return [new MermaidStatement(MermaidText::decode($title), $start + $offset + \strlen($indent), $line, \strlen($indent)), $configuration];
    }
}
