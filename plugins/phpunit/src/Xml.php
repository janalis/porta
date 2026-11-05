<?php

declare(strict_types=1);

namespace Porta\PhpUnit;

/** The two reports' shared reading: a document whose root is named, and the nodes a path selects. */
final class Xml
{
    /**
     * $xml parsed, its root element named $root. libxml's error mode and
     * buffer are left as they were found: a caller that collects its own
     * errors does not inherit these.
     *
     * @throws \RuntimeException naming $what, when $xml is no such document
     */
    public static function load(string $xml, string $root, string $what): \SimpleXMLElement
    {
        $previous = \libxml_use_internal_errors(true);
        $document = \simplexml_load_string($xml, options: \LIBXML_NONET);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        if (false === $document || $root !== $document->getName()) {
            throw new \RuntimeException('Not a '.$what);
        }

        return $document;
    }

    /** @return list<\SimpleXMLElement> what $path selects from $node, none when it selects nothing */
    public static function all(\SimpleXMLElement $node, string $path): array
    {
        $found = $node->xpath($path);

        return \is_array($found) ? \array_values($found) : [];
    }
}
