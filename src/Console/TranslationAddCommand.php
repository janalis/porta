<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds one message to every catalog of a set, under the next free id.
 *
 * A new string is a `<trans-unit>` in seven files — core's or one plugin's
 * `messages.<locale>.xlf` — with the SAME id in each, one past the highest any
 * of them uses. By hand that is seven edits and an id picked by eye, and a
 * duplicate id does not fail loudly: `LenientXliffLoader` turns that catalog
 * into an empty one and the whole locale quietly falls back to English. The
 * catalogs are the most-edited files in the repository after the decision
 * history, so the mechanical half is worth a command.
 *
 * It refuses rather than guesses: every locale's target must be given (an
 * English string shipped as German is a translation nobody will notice is
 * missing), and a source the English catalog already has is reported with its
 * id instead of added twice. The locale set is the catalog files present, so a
 * plugin's set works the same as core's; `CatalogCompletenessTest` is what
 * insists all seven exist.
 *
 * The unit is written as TEXT before `</body>`, not through a DOM round trip,
 * so nothing else in a hand-maintained file moves.
 */
#[AsCommand(
    name: 'i18n:add',
    description: 'Add one message to every catalog of a set, under the next free id',
    help: <<<'HELP'
        Appends a <trans-unit> with the same new id to each <info>messages.<locale>.xlf</info>
        in <info>translations/</info>, or in <info>plugins/<name>/translations/</info> with
        <info>--plugin</info>. Every non-English locale needs a <info>--target</info>.

          <info>i18n:add "Save All" --target="fr:Tout enregistrer" --target="de:Alle speichern" …</info>
          <info>i18n:add "Blame" --plugin=git --target="fr:Annotations" …</info>
        HELP,
)]
final class TranslationAddCommand extends Command
{
    use CommandErrorStream;

    private readonly string $rootDir;

    /** @param string|null $rootDir the tree whose catalogs to edit; null is this checkout */
    public function __construct(?string $rootDir = null)
    {
        parent::__construct();
        $this->rootDir = $rootDir ?? \dirname(__DIR__, levels: 2);
    }

    /** @param list<string> $target */
    public function __invoke(
        OutputInterface $output,
        #[Argument('The English message, exactly as the code passes it to trans()')]
        string $source,
        #[Option('Bundled plugin whose catalogs to edit; omit for core')]
        ?string $plugin = null,
        #[Option('A translation as <locale>:<text>, once per non-English locale')]
        array $target = [],
    ): int {
        $errors = self::errorOutput($output);
        $dir = null === $plugin ? $this->rootDir.'/translations' : $this->rootDir.'/plugins/'.$plugin.'/translations';
        $catalogs = self::catalogs($dir);
        $targets = ['en' => $source];
        $problem = \array_key_exists('en', $catalogs)
            ? self::targetProblem($target, $catalogs, $targets) ?? self::duplicateProblem($catalogs['en'], $source)
            : 'i18n:add: no messages.en.xlf in '.$dir;
        if (null !== $problem) {
            $errors->writeln($problem);

            return Command::FAILURE;
        }

        $id = 1;
        foreach ($catalogs as $xml) {
            foreach (self::ids($xml) as $taken) {
                $id = \max($id, $taken + 1);
            }
        }
        foreach ($catalogs as $locale => $xml) {
            $unit = \sprintf(
                "      <trans-unit id=\"%d\">\n        <source>%s</source>\n        <target>%s</target>\n      </trans-unit>\n",
                $id,
                self::escape($source),
                self::escape($targets[$locale]),
            );
            $at = \strrpos($xml, needle: '    </body>');
            \assert(false !== $at, description: 'catalogs() only returns files with a </body>');
            \file_put_contents($dir.'/messages.'.$locale.'.xlf', \substr_replace($xml, $unit, $at, length: 0));
        }

        $output->writeln(\sprintf('Added id %d to %d catalogs in %s', $id, \count($catalogs), \substr($dir, \strlen($this->rootDir) + 1)));

        return Command::SUCCESS;
    }

    /**
     * Reads each <locale>:<text> pair into $targets; why they cannot be
     * added — a malformed pair, or a locale left without one — or null.
     *
     * @param list<string>          $pairs
     * @param array<string, string> $catalogs
     * @param array<string, string> $targets
     */
    private static function targetProblem(array $pairs, array $catalogs, array &$targets): ?string
    {
        $problem = null;
        foreach ($pairs as $pair) {
            $colon = \strpos($pair, needle: ':');
            $locale = false === $colon ? $pair : \substr($pair, offset: 0, length: $colon);
            $text = false === $colon ? '' : \substr($pair, $colon + 1);
            if (!\array_key_exists($locale, $catalogs) || 'en' === $locale || '' === $text) {
                $problem = \sprintf('i18n:add: "%s" is not <locale>:<text> for one of %s', $pair, \implode(', ', \array_keys($catalogs)));
                break;
            }
            $targets[$locale] = $text;
        }
        $missing = null === $problem ? \array_diff(\array_keys($catalogs), \array_keys($targets)) : [];

        return [] === $missing ? $problem : 'i18n:add: no --target for '.\implode(', ', $missing).' — every locale needs its translation';
    }

    /** Why $source cannot be added because the English catalog already has it, or null. */
    private static function duplicateProblem(string $english, string $source): ?string
    {
        $existing = self::idOf($english, $source);

        return null === $existing ? null : \sprintf('i18n:add: the English catalog already has "%s" as id %d', $source, $existing);
    }

    /** @return array<string, string> locale => file contents, for every catalog with a body to append to */
    private static function catalogs(string $dir): array
    {
        $catalogs = [];
        $files = \glob($dir.'/messages.*.xlf');
        foreach (\is_array($files) ? $files : [] as $file) {
            $xml = (string) \file_get_contents($file);
            if (!\str_contains($xml, '    </body>')) {
                continue;
            }
            $catalogs[\substr(\basename($file, suffix: '.xlf'), \strlen('messages.'))] = $xml;
        }

        return $catalogs;
    }

    /** @return list<int> */
    private static function ids(string $xml): array
    {
        $matches = [];
        \preg_match_all('/<trans-unit id="(\d+)"/', $xml, $matches);

        return \array_values(\array_map(\intval(...), $matches[1]));
    }

    private static function idOf(string $xml, string $source): ?int
    {
        $match = [];
        $found = \preg_match('/<trans-unit id="(\d+)">\s*<source>'.\preg_quote(self::escape($source), delimiter: '/').'<\/source>/', $xml, $match);

        return 1 === $found ? (int) $match[1] : null;
    }

    /** The catalogs' own escaping: `&quot;` for a double quote, a literal apostrophe. */
    private static function escape(string $text): string
    {
        return \htmlspecialchars($text, \ENT_XML1 | \ENT_COMPAT);
    }
}
