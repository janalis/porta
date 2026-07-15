<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;

/**
 * Guards the shipped XLIFF catalogs: every English source id must exist in all
 * six target catalogs (no missing keys), each file must be well-formed XLIFF with
 * a source and target per unit, and the target-language attribute must match.
 */
final class CatalogCompletenessTest extends TestCase
{
    private const string DIR = __DIR__.'/../../../translations';

    /** @return list<string> the <source> texts of a catalog, in document order */
    private function sources(string $locale): array
    {
        $xml = simplexml_load_file(self::DIR.'/messages.'.$locale.'.xlf');
        self::assertNotFalse($xml, "messages.{$locale}.xlf is not valid XML");
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

    public function testEveryEnglishSourceExistsInAllSixTargetCatalogs(): void
    {
        $english = $this->sources('en');
        self::assertNotEmpty($english, 'the English catalog carries the canonical source list');

        foreach (['fr', 'de', 'es', 'it', 'ja', 'he'] as $locale) {
            $have = $this->sources($locale);
            $missing = array_values(array_diff($english, $have));
            self::assertSame([], $missing, "messages.{$locale}.xlf is missing sources: ".implode(' | ', $missing));
        }
    }

    public function testEveryUnitHasANonEmptyTargetInEveryCatalog(): void
    {
        foreach (Translator::LOCALES as $locale) {
            $xml = simplexml_load_file(self::DIR.'/messages.'.$locale.'.xlf');
            self::assertNotFalse($xml);
            $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

            $units = $xml->xpath('//x:trans-unit');
            foreach ($units ? $units : [] as $unit) {
                $unit->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
                $target = $unit->xpath('x:target');
                $target = $target ? $target : [];
                self::assertNotSame([], $target, "a trans-unit in messages.{$locale}.xlf has no <target>");
                self::assertNotSame('', (string) $target[0], "a trans-unit in messages.{$locale}.xlf has an empty <target>");
            }
        }
    }

    public function testTargetLanguageAttributeMatchesTheFile(): void
    {
        foreach (Translator::LOCALES as $locale) {
            $xml = simplexml_load_file(self::DIR.'/messages.'.$locale.'.xlf');
            self::assertNotFalse($xml);
            $files = $xml->xpath('//*[local-name()="file"]');
            $file = ($files ? $files : [])[0] ?? null;
            self::assertNotNull($file);
            self::assertSame($locale, (string) $file['target-language']);
        }
    }
}
