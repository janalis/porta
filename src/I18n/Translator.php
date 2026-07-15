<?php

declare(strict_types=1);

namespace Porta\I18n;

use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator as SymfonyTranslator;

/**
 * The UI translator: a thin wrapper over {@see SymfonyTranslator} that loads the
 * XLIFF catalogs shipped in the package's `translations/` directory and answers
 * {@see trans()} against the active locale, falling back to English. The message
 * ids are the English source strings, so an untranslated (or 'en') lookup
 * returns the original English text.
 *
 * XLIFF is parsed here with ext-simplexml (avoiding a symfony/config dependency)
 * into `source => target` maps fed to the array loader.
 */
final class Translator
{
    /** The supported UI locales, in menu order (English first). */
    public const array LOCALES = ['en', 'fr', 'de', 'es', 'it', 'ja', 'he'];

    /** Native display name per locale, for the settings language picker. */
    public const array LOCALE_NAMES = [
        'en' => 'English',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'es' => 'Español',
        'it' => 'Italiano',
        'ja' => '日本語',
        'he' => 'עברית',
    ];

    /** Locales written right-to-left. */
    private const array RTL = ['he'];

    private readonly SymfonyTranslator $translator;

    public function __construct(string $locale = 'en', ?string $translationsDir = null)
    {
        $dir = $translationsDir ?? \dirname(__DIR__, levels: 2).'/translations';
        $this->translator = new SymfonyTranslator(self::normalize($locale));
        $this->translator->setFallbackLocales(['en']);
        $this->translator->addLoader('array', new ArrayLoader());
        foreach (self::LOCALES as $l) {
            $file = $dir.'/messages.'.$l.'.xlf';
            if (is_file($file)) {
                $this->translator->addResource('array', self::parseXliff($file), $l);
            }
        }
    }

    /**
     * Parse an XLIFF 1.2 file into a `source => target` map (an empty target
     * keeps the source, so a stubbed entry still resolves to English).
     *
     * @return array<string, string>
     */
    private static function parseXliff(string $file): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file);
        libxml_use_internal_errors($previous);
        if (false === $xml) {
            return [];
        }
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

        $map = [];
        $units = $xml->xpath('//x:trans-unit');
        foreach (\is_array($units) ? $units : [] as $unit) {
            $source = self::childText($unit, 'source');
            $target = self::childText($unit, 'target');
            if ('' !== $source) {
                $map[$source] = '' !== $target ? $target : $source;
            }
        }

        return $map;
    }

    /** The text of a namespaced child element (`source`/`target`) of a trans-unit. */
    private static function childText(\SimpleXMLElement $unit, string $tag): string
    {
        $unit->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
        $found = $unit->xpath('x:'.$tag);
        $nodes = \is_array($found) ? $found : [];

        return [] !== $nodes ? (string) $nodes[0] : '';
    }

    /**
     * Translate an English source string for the active locale (the string
     * itself when untranslated). Placeholders use the `%name%` convention.
     *
     * @param array<string, string|int> $params
     */
    public function trans(string $id, array $params = []): string
    {
        return $this->translator->trans($id, $params);
    }

    public function setLocale(string $locale): void
    {
        $this->translator->setLocale(self::normalize($locale));
    }

    public function locale(): string
    {
        return $this->translator->getLocale();
    }

    /** 'rtl' for right-to-left locales (Hebrew), else 'ltr'. */
    public function direction(): string
    {
        return \in_array($this->locale(), self::RTL, strict: true) ? 'rtl' : 'ltr';
    }

    public function isRtl(): bool
    {
        return 'rtl' === $this->direction();
    }

    /** An unknown locale falls back to English. */
    private static function normalize(string $locale): string
    {
        return \in_array($locale, self::LOCALES, strict: true) ? $locale : 'en';
    }
}
