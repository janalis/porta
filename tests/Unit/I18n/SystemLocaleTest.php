<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\I18n\SystemLocale;

final class SystemLocaleTest extends TestCase
{
    /** @return iterable<string, array{array<string, string|false>, string, 2?: string}> */
    public static function environments(): iterable
    {
        yield 'nothing set' => [[], 'en'];
        yield 'LANG with a codeset' => [['LANG' => 'fr_FR.UTF-8'], 'fr'];
        yield 'LANG with a modifier' => [['LANG' => 'de_DE@euro'], 'de'];
        yield 'a BCP 47 spelling' => [['LANG' => 'ja-JP'], 'ja'];
        yield 'a bare language' => [['LANG' => 'it'], 'it'];
        yield 'upper case' => [['LANG' => 'ES_es.UTF-8'], 'es'];
        yield 'a right-to-left system' => [['LANG' => 'he_IL.UTF-8'], 'he'];
        yield 'a language without a catalog' => [['LANG' => 'pt_BR.UTF-8'], 'en'];
        yield 'LC_MESSAGES outranks LANG' => [['LC_MESSAGES' => 'fr_FR', 'LANG' => 'de_DE'], 'fr'];
        yield 'LC_ALL outranks both' => [['LC_ALL' => 'ja_JP', 'LC_MESSAGES' => 'fr_FR', 'LANG' => 'de_DE'], 'ja'];
        yield 'an unsupported winner does not fall through' => [['LC_ALL' => 'pt_BR', 'LANG' => 'fr_FR'], 'en'];
        yield 'empty is unset' => [['LC_ALL' => '', 'LANG' => 'fr_FR'], 'fr'];
        yield 'false is unset' => [['LC_ALL' => false, 'LANG' => 'fr_FR'], 'fr'];
        yield 'C is no choice' => [['LC_ALL' => 'C', 'LANG' => 'fr_FR'], 'fr'];
        yield 'POSIX is no choice' => [['LC_MESSAGES' => 'POSIX', 'LANG' => 'de_DE'], 'de'];
        yield 'C.UTF-8 alone' => [['LANG' => 'C.UTF-8'], 'en'];
        // Windows sets no POSIX variable; ICU's default locale speaks for it.
        yield 'the fallback when nothing is set' => [[], 'fr', 'fr-FR'];
        yield 'the fallback past a C locale' => [['LANG' => 'C'], 'de', 'de_DE'];
        yield 'a variable outranks the fallback' => [['LANG' => 'ja_JP.UTF-8'], 'ja', 'fr_FR'];
        yield "ICU's POSIX default is English" => [[], 'en', 'en_US_POSIX'];
        yield 'an unsupported fallback is English' => [[], 'en', 'pt_BR'];
    }

    /** @param array<string, string|false> $env */
    #[DataProvider('environments')]
    public function testReadsTheLocaleTheSystemAsksFor(array $env, string $expected, string $fallback = ''): void
    {
        $this->assertSame($expected, SystemLocale::fromEnvironment($env, $fallback));
    }
}
