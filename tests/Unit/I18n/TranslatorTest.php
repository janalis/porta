<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Tests\Support\TempProject;

final class TranslatorTest extends TestCase
{
    private function xliff(string $locale, string $body): string
    {
        return (
            '<?xml version="1.0" encoding="utf-8"?>'
            .'<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">'
            .'<file source-language="en" target-language="'
            .$locale
            .'" datatype="plaintext" original="messages"><body>'
            .$body
            .'</body></file></xliff>'
        );
    }

    private function unit(string $source, string $target): string
    {
        return '<trans-unit id="x"><source>'.$source.'</source><target>'.$target.'</target></trans-unit>';
    }

    public function testMarkupTranslatesTheTagsAndEscapesEveryParam(): void
    {
        $project = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Saved &lt;info&gt;%file%&lt;/&gt;', '&lt;info&gt;%file%&lt;/&gt; enregistré'))]);
        $translator = new Translator('fr', $project->dir);

        $markup = $translator->markup('Saved <info>%file%</>', ['%file%' => '<b>.php', '%n%' => 3]);

        $this->assertSame('<info>\\<b\\>.php</> enregistré', $markup->source, 'the catalog keeps its tags; the file name is escaped');
        $this->assertSame('<b>.php enregistré', $markup->plain());
    }

    public function testTranslatesTheActiveLocaleAndFallsBackToEnglish(): void
    {
        $project = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Save File', 'Enregistrer le fichier'))]);
        $translator = new Translator('fr', $project->dir);

        $this->assertSame('Enregistrer le fichier', $translator->trans('Save File'));
        $this->assertSame('Unknown String', $translator->trans('Unknown String'), 'an untranslated id returns itself (English)');
        $this->assertSame('fr', $translator->locale());
    }

    public function testAutoIsTheSystemLocale(): void
    {
        $project = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Save File', 'Enregistrer le fichier'))]);
        $translator = new Translator('auto', $project->dir, systemLocale: 'fr');

        $this->assertSame('fr', $translator->locale());
        $this->assertSame('fr', $translator->systemLocale());
        $this->assertSame('Enregistrer le fichier', $translator->trans('Save File'));

        $translator->setLocale('en');
        $translator->setLocale('auto');
        $this->assertSame('fr', $translator->locale(), 'a live switch back to auto resolves too');
    }

    public function testAutoFollowsARightToLeftSystem(): void
    {
        $translator = new Translator('auto', systemLocale: 'he');

        $this->assertTrue($translator->isRtl());
    }

    public function testAnUnsupportedSystemLocaleIsEnglish(): void
    {
        $this->assertSame('en', new Translator('auto', systemLocale: 'pt')->locale());
    }

    public function testACacheDirDumpsTheCatalogAndStaysHonestAgainstEdits(): void
    {
        // One staleness leg only: Symfony's SelfCheckingResourceChecker
        // memoizes freshness PER PROCESS, so a translator that already
        // fresh-checked a dump poisons any later staleness assertion in the
        // same test. Real boots are separate processes — the dump's read-back
        // is symfony's contract; what is Porta's to pin is that the dir is
        // threaded through (the glob) and that debug=true re-parses an edit.
        $catalogs = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Save File', 'Enregistrer le fichier'))]);
        $cache = new TempProject();

        $first = new Translator('fr', $catalogs->dir, $cache->dir);
        $this->assertSame('Enregistrer le fichier', $first->trans('Save File'));
        $this->assertNotSame([], \glob($cache->dir.'/catalogue.fr.*.php'), 'the first trans() dumped the parsed catalog');

        $catalogs->write('messages.fr.xlf', $this->xliff('fr', $this->unit('Save File', 'Sauvegarder')));
        \touch($catalogs->path('messages.fr.xlf'), \time() + 2); // a same-second edit needs a distinct mtime
        $second = new Translator('fr', $catalogs->dir, $cache->dir);
        $this->assertSame('Sauvegarder', $second->trans('Save File'), 'the edited catalog is re-parsed, not served stale');
    }

    public function testACatalogDirectoryServesAPluginsOwnStrings(): void
    {
        $core = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Save File', 'Enregistrer le fichier'))]);
        $plugin = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Show Blame', 'Afficher l’annotation'))]);
        $translator = new Translator('en', $core->dir);
        $translator->setLocale('fr');

        $translator->addCatalogDirectory($plugin->dir);

        $this->assertSame('fr', $translator->locale(), 'the rebuild keeps the active locale');
        $this->assertSame('Afficher l’annotation', $translator->trans('Show Blame'), "the plugin's own string");
        $this->assertSame('Enregistrer le fichier', $translator->trans('Save File'), "core's strings still serve");
    }

    public function testCoreWinsAStringAPluginCataloguesToo(): void
    {
        // A plugin adds strings; it must not be able to retranslate core's —
        // `Cancel` or `Run` would change across the whole IDE.
        $core = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Cancel', 'Annuler'))]);
        $plugin = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Cancel', 'Abandonner'))]);
        $translator = new Translator('fr', $core->dir);

        $translator->addCatalogDirectory($plugin->dir);

        $this->assertSame('Annuler', $translator->trans('Cancel'));
    }

    public function testACatalogDirectoryAddedAfterADumpIsNotServedTheStaleDump(): void
    {
        // The dumped catalog is named after cacheVary alone and re-checks only
        // the files it recorded, and the cache dir is shared machine-wide: a
        // dir registered after the first trans() of a boot — or a project
        // with one more plugin than the last — must not be served a dump that
        // was made without it.
        $core = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Save File', 'Enregistrer'))]);
        $plugin = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Show Blame', 'Annoter'))]);
        $cache = new TempProject();
        $translator = new Translator('fr', $core->dir, $cache->dir);
        $this->assertSame('Show Blame', $translator->trans('Show Blame'), 'dumped without the plugin');

        $translator->addCatalogDirectory($plugin->dir);
        $this->assertSame('Annoter', $translator->trans('Show Blame'));
        $translator->addCatalogDirectory($plugin->dir);
        $this->assertSame('Annoter', $translator->trans('Show Blame'));

        $this->assertCount(2, (array) \glob($cache->dir.'/catalogue.fr.*.php'), 'one dump per dir set: a dir added twice is one set, not a third dump');
    }

    public function testPlaceholdersAreInterpolated(): void
    {
        $project = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Committed: %summary%', 'Validé : %summary%'))]);
        $translator = new Translator('fr', $project->dir);

        $this->assertSame('Validé : init', $translator->trans('Committed: %summary%', ['%summary%' => 'init']));
    }

    public function testAMalformedCatalogDegradesToEnglishInsteadOfThrowing(): void
    {
        // The catalog loads lazily inside the first trans() for its locale,
        // where an exception would take the whole UI down; a broken file must
        // cost only its translations.
        $project = new TempProject(['messages.fr.xlf' => 'not xml at all']);
        $translator = new Translator('fr', $project->dir);

        $this->assertSame('Save File', $translator->trans('Save File'), 'the id (English) serves as the answer');
    }

    public function testACatalogVanishingAfterBootStillDegradesToEnglish(): void
    {
        // The is_file() check runs at construction but the parse runs at the
        // first trans() for the locale — a package upgrade can swap the
        // translations dir out in between, which throws NotFoundResourceException
        // (a SIBLING of InvalidResourceException) unless the loader covers it.
        $project = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Save File', 'Enregistrer'))]);
        $translator = new Translator('fr', $project->dir);
        \unlink($project->path('messages.fr.xlf'));

        $this->assertSame('Save File', $translator->trans('Save File'), 'the id (English) serves as the answer');
    }

    public function testTheEnglishCatalogVanishingAfterBootStillDegradesGracefully(): void
    {
        // Unlike a non-English locale (which falls back to 'en' and lets
        // Symfony's own Translator swallow the NotFoundResourceException),
        // English IS the fallback — nothing catches it upstream, so this is
        // the one scenario where LenientXliffLoader's own catch is load-bearing.
        $project = new TempProject(['messages.en.xlf' => $this->xliff('en', $this->unit('Save File', 'Save File'))]);
        $translator = new Translator('en', $project->dir);
        \unlink($project->path('messages.en.xlf'));

        $this->assertSame('Save File', $translator->trans('Save File'), 'the id serves as the answer, no throw');
    }

    public function testAnEmptyTargetFallsBackToTheSource(): void
    {
        $project = new TempProject(['messages.de.xlf' => $this->xliff('de', $this->unit('Save File', ''))]);
        $translator = new Translator('de', $project->dir);

        $this->assertSame('Save File', $translator->trans('Save File'), 'a stubbed (empty) target keeps English');
    }

    public function testSetLocaleSwitchesTranslations(): void
    {
        $project = new TempProject([
            'messages.fr.xlf' => $this->xliff('fr', $this->unit('Save File', 'Enregistrer')),
            'messages.de.xlf' => $this->xliff('de', $this->unit('Save File', 'Speichern')),
        ]);
        $translator = new Translator('en', $project->dir);

        $this->assertSame('Save File', $translator->trans('Save File'), 'English is the source');
        $translator->setLocale('fr');
        $this->assertSame('Enregistrer', $translator->trans('Save File'));
        $translator->setLocale('de');
        $this->assertSame('Speichern', $translator->trans('Save File'));
    }

    public function testDirectionAndRtl(): void
    {
        $tempProject = new TempProject();
        $translator = new Translator('he', $tempProject->dir);
        $this->assertSame('rtl', $translator->direction());
        $this->assertTrue($translator->isRtl());

        $translator->setLocale('en');
        $this->assertSame('ltr', $translator->direction());
        $this->assertFalse($translator->isRtl());
    }

    public function testAnUnknownLocaleNormalizesToEnglish(): void
    {
        $tempProject = new TempProject();
        $translator = new Translator('klingon', $tempProject->dir);
        $this->assertSame('en', $translator->locale());
        $translator->setLocale('martian');
        $this->assertSame('en', $translator->locale());
    }

    public function testMalformedXliffIsIgnored(): void
    {
        $project = new TempProject(['messages.fr.xlf' => '<xliff not valid']);
        $translator = new Translator('fr', $project->dir);

        $this->assertSame('Save File', $translator->trans('Save File'), 'a broken catalog degrades to English');
    }

    public function testDefaultsToThePackageTranslationsDirectory(): void
    {
        // No dir override → loads the shipped translations/ (currently source-only).
        $translator = new Translator('en');
        $this->assertSame('Some Menu Item', $translator->trans('Some Menu Item'));
        $this->assertContains('fr', Translator::LOCALES);
    }

    public function testEveryLocaleHasANativeName(): void
    {
        foreach (Translator::LOCALES as $locale) {
            $this->assertArrayHasKey($locale, Translator::LOCALE_NAMES, "{$locale} needs a display name");
            $this->assertNotSame('', Translator::LOCALE_NAMES[$locale]);
        }
        $this->assertSame('English', Translator::LOCALE_NAMES['en']);
    }
}
