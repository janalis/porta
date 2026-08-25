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

    public function testTranslatesTheActiveLocaleAndFallsBackToEnglish(): void
    {
        $project = new TempProject(['messages.fr.xlf' => $this->xliff('fr', $this->unit('Save File', 'Enregistrer le fichier'))]);
        $translator = new Translator('fr', $project->dir);

        $this->assertSame('Enregistrer le fichier', $translator->trans('Save File'));
        $this->assertSame('Unknown String', $translator->trans('Unknown String'), 'an untranslated id returns itself (English)');
        $this->assertSame('fr', $translator->locale());
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
        unlink($project->path('messages.fr.xlf'));

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
        unlink($project->path('messages.en.xlf'));

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
