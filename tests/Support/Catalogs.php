<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * The shipped XLIFF catalog SETS — core's `translations/` and every bundled
 * plugin's own `plugins/<name>/translations/` — and the one reader of their
 * `<source>` texts the catalog gates share.
 *
 * A set is enumerated from the tree, not listed: a plugin that grows a
 * catalog is under every gate from its first commit.
 */
final class Catalogs
{
    public const string ROOT = __DIR__.'/../..';

    /** The set name core's catalog goes by; every other set is a plugin name. */
    public const string CORE = 'core';

    /** @return array<string, string> set name => catalog dir, core first */
    public static function sets(): array
    {
        $sets = [self::CORE => self::ROOT.'/translations'];
        $dirs = \glob(self::ROOT.'/plugins/*/translations', \GLOB_ONLYDIR);
        foreach (\is_array($dirs) ? $dirs : [] as $dir) {
            $sets[\basename(\dirname($dir))] = $dir;
        }

        return $sets;
    }

    /**
     * The `<source>` texts of one catalog file, in document order.
     *
     * @return list<string>
     */
    public static function sources(string $file): array
    {
        $xml = \simplexml_load_file($file);
        if (false === $xml) {
            throw new \RuntimeException($file.' is not valid XML');
        }
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

        $sources = [];
        $units = $xml->xpath('//x:trans-unit');
        foreach ($units ? $units : [] as $unit) {
            $unit->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
            $node = $unit->xpath('x:source');
            $node = $node ? $node : [];
            if ([] !== $node) {
                $sources[] = (string) $node[0];
            }
        }

        return $sources;
    }

    /** @return list<string> the English sources of one set */
    public static function englishSources(string $set): array
    {
        return self::sources(self::sets()[$set].'/messages.en.xlf');
    }
}
