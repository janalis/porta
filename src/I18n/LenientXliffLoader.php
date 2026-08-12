<?php

declare(strict_types=1);

namespace Porta\I18n;

use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Exception\NotFoundResourceException;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * The stock {@see XliffFileLoader} with Porta's two catalog conventions on
 * top. The load runs lazily inside the first trans() for a locale, so a
 * malformed file degrades to an empty catalogue instead of throwing there —
 * an untranslated (English) label is the correct failure mode for a broken
 * translation file. And a stubbed entry (empty `<target/>`) keeps its source:
 * the message ids ARE the English strings, so falling back to the id is
 * falling back to English.
 */
final class LenientXliffLoader extends XliffFileLoader
{
    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        try {
            $catalogue = parent::load($resource, $locale, $domain);
        } catch (InvalidResourceException|NotFoundResourceException) {
            // NotFound is a sibling, not a subclass: the file was is_file()-
            // checked at boot registration but the load runs lazily, so a
            // catalog replaced or removed in between must still degrade.
            return new MessageCatalogue($locale);
        }

        foreach ($catalogue->all($domain) as $id => $translation) {
            if ('' === $translation) {
                $catalogue->set((string) $id, (string) $id, $domain);
            }
        }

        return $catalogue;
    }
}
