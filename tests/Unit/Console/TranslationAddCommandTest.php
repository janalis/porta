<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Console\TranslationAddCommand;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `i18n:add` against a three-locale set: the id it picks, the text it writes
 * into each file, and every input it refuses without touching a file.
 */
final class TranslationAddCommandTest extends TestCase
{
    private static function catalog(string $locale, string $units): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
              <file source-language="en" target-language="{$locale}" datatype="plaintext" original="messages">
                <body>
            {$units}    </body>
              </file>
            </xliff>

            XML;
    }

    private static function unit(int $id, string $source, string $target): string
    {
        return "      <trans-unit id=\"{$id}\">\n        <source>{$source}</source>\n        <target>{$target}</target>\n      </trans-unit>\n";
    }

    /** Core's set in `translations/`, where the French file's highest id is ahead of the others. */
    private function project(string $dir = 'translations'): TempProject
    {
        return new TempProject([
            $dir.'/messages.en.xlf' => self::catalog('en', self::unit(3, 'Open &quot;%name%&quot; (copy)', 'Open &quot;%name%&quot; (copy)')),
            $dir.'/messages.fr.xlf' => self::catalog('fr', self::unit(3, 'Open &quot;%name%&quot; (copy)', 'Ouvrir « %name% »').self::unit(9, 'Stale', 'Périmé')),
            $dir.'/messages.de.xlf' => self::catalog('de', self::unit(3, 'Open &quot;%name%&quot; (copy)', '„%name%“ öffnen')),
        ]);
    }

    private static function read(TempProject $project, string $path): string
    {
        return (string) \file_get_contents($project->path($path));
    }

    /** @param array<string, mixed> $input */
    private static function execute(TempProject $project, array $input): CommandTester
    {
        $tester = new CommandTester(new TranslationAddCommand($project->path()));
        $tester->execute($input);

        return $tester;
    }

    public function testItAddsTheSameNextIdToEveryCatalogWithEachLocalesTarget(): void
    {
        $project = $this->project();

        $tester = self::execute($project, ['source' => 'Don\'t "save"', '--target' => ['fr:Ne pas « enregistrer » : non', 'de:Nicht „speichern“']]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame("Added id 10 to 3 catalogs in translations\n", $tester->getDisplay(), 'one past the highest id in ANY file of the set');
        $this->assertSame(
            self::catalog('en', self::unit(3, 'Open &quot;%name%&quot; (copy)', 'Open &quot;%name%&quot; (copy)').self::unit(10, 'Don\'t &quot;save&quot;', 'Don\'t &quot;save&quot;')),
            self::read($project, 'translations/messages.en.xlf'),
            'appended before </body>, escaped the way the catalogs are: &quot; and a literal apostrophe',
        );
        $this->assertStringEndsWith(
            self::unit(10, 'Don\'t &quot;save&quot;', 'Ne pas « enregistrer » : non')."    </body>\n  </file>\n</xliff>\n",
            self::read($project, 'translations/messages.fr.xlf'),
        );
        $this->assertStringContainsString(self::unit(10, 'Don\'t &quot;save&quot;', 'Nicht „speichern“'), self::read($project, 'translations/messages.de.xlf'));
    }

    public function testAPluginsSetIsEditedInsteadOfCores(): void
    {
        $project = $this->project('plugins/git/translations');

        $tester = self::execute($project, ['source' => 'Blame', '--plugin' => 'git', '--target' => ['fr:Annotations', 'de:Annotationen']]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame("Added id 10 to 3 catalogs in plugins/git/translations\n", $tester->getDisplay());
        $this->assertStringContainsString(self::unit(10, 'Blame', 'Annotationen'), self::read($project, 'plugins/git/translations/messages.de.xlf'));
    }

    public function testAFileWithNoBodyToAppendToIsNotACatalog(): void
    {
        $project = $this->project();
        $project->write('translations/messages.aa.xlf', '<xliff/>');

        $tester = self::execute($project, ['source' => 'New', '--target' => ['fr:Nouveau', 'de:Neu']]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), 'aa sorts first and is skipped, not the end of the walk');
        $this->assertSame('<xliff/>', self::read($project, 'translations/messages.aa.xlf'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'a locale without its target' => [['--target' => ['fr:Nouveau']], 'no --target for de — every locale needs its translation'];
        yield 'no colon' => [['--target' => ['fr', 'de:Neu']], '"fr" is not <locale>:<text> for one of de, en, fr'];
        yield 'an empty text' => [['--target' => ['fr:', 'de:Neu']], '"fr:" is not <locale>:<text>'];
        yield 'a locale the set does not have' => [['--target' => ['pt:Novo', 'fr:Nouveau', 'de:Neu']], '"pt:Novo" is not <locale>:<text>'];
        yield 'English, which is the source' => [['--target' => ['en:New', 'fr:Nouveau', 'de:Neu']], '"en:New" is not <locale>:<text>'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('refusals')]
    public function testItRefusesTargetsItCannotWriteEveryLocaleFrom(array $input, string $message): void
    {
        $project = $this->project();
        $before = self::read($project, 'translations/messages.de.xlf');

        $tester = self::execute($project, ['source' => 'New', ...$input]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString($message, $tester->getDisplay());
        $this->assertSame($before, self::read($project, 'translations/messages.de.xlf'), 'nothing written');
    }

    public function testASourceTheEnglishCatalogHasIsReportedWithItsIdNotAddedTwice(): void
    {
        $project = $this->project();

        $tester = self::execute($project, ['source' => 'Open "%name%" (copy)', '--target' => ['fr:x', 'de:y']]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('the English catalog already has "Open "%name%" (copy)" as id 3', $tester->getDisplay(), 'matched through the escaping, the parentheses quoted');
        $this->assertStringNotContainsString('id="10"', self::read($project, 'translations/messages.en.xlf'));
    }

    public function testWithNoRootItEditsThisCheckoutsCatalogs(): void
    {
        $en = \dirname(__DIR__, levels: 3).'/translations/messages.en.xlf';
        $before = (string) \file_get_contents($en);
        \preg_match('/<trans-unit id="(\d+)">\s*<source>([^<]+)<\/source>/', $before, $first);
        $targets = \array_map(static fn (string $l): string => $l.':x', ['fr', 'de', 'es', 'it', 'ja', 'he']);

        $tester = new CommandTester(new TranslationAddCommand());
        $tester->execute(['source' => \html_entity_decode($first[2], \ENT_XML1 | \ENT_COMPAT), '--target' => $targets]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'refused, so the real catalogs stay untouched');
        $this->assertStringContainsString('as id '.$first[1], $tester->getDisplay(), 'found in the repository\'s own English catalog');
        $this->assertSame($before, (string) \file_get_contents($en));
    }

    public function testASetWithNoEnglishCatalogIsRefused(): void
    {
        $project = new TempProject(['translations/messages.fr.xlf' => self::catalog('fr', '')]);

        $tester = self::execute($project, ['source' => 'New', '--target' => ['fr:Nouveau']]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('no messages.en.xlf in '.$project->path().'/translations', $tester->getDisplay());
    }

    public function testAnEmptySetStartsAtOne(): void
    {
        $project = new TempProject(['translations/messages.en.xlf' => self::catalog('en', '')]);

        $tester = self::execute($project, ['source' => 'First']);

        $this->assertSame("Added id 1 to 1 catalogs in translations\n", $tester->getDisplay());
    }
}
