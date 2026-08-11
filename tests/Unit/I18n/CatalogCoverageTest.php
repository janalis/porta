<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * The missing direction of {@see CatalogCompletenessTest}: that test guards
 * en -> six targets, but a trans('New string') literal absent from
 * messages.en.xlf failed SILENTLY to the identity fallback — nothing noticed
 * a UI string that never reached the catalogs. This gate collects every
 * statically-knowable UI string (literal ->trans() ids across src/ and the
 * bundled plugins, plus the two label tables translated at render time with
 * dynamic ids, which is exactly what defeats an extractor) and requires each
 * to exist as an en <source>.
 */
final class CatalogCoverageTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    /**
     * Ids the first run of this gate found already missing — standing debt,
     * pinned in BOTH directions: a NEW untranslated literal fails (it will be
     * absent from this list), and translating one of these fails until its
     * entry here is removed, so the list can only shrink.
     */
    private const array KNOWN_GAPS = [
        'Add to Search Scopes',
        'Added %dir% to search scopes',
        'Breadcrumbs bar',
        'Cherry-pick',
        'Cherry-picked %sha%',
        'Copied %sha%',
        'Copy Hash',
        'Create Branch from Commit…',
        'Cycle Line Numbers',
        'Debug',
        'Debug adapter',
        'Delete Branch…',
        'Exported %count% results',
        'Find in Files',
        'Find in Project: %query%',
        'Graph rendering',
        'Help: Back',
        'Help: Follow Link',
        'Help: Go to Bottom',
        'Help: Go to Top',
        'Help: Next Link',
        'Help: Page Down',
        'Help: Page Up',
        'Help: Previous Link',
        'Help: Scroll Down',
        'Help: Scroll Up',
        'Indent guides',
        'Installed, but composer install failed (exit code %code%)',
        'Installed, but composer is not available to install its dependencies',
        'Invalid JSON — not saved',
        'JSON merged into the DAP launch request',
        'Join Lines',
        'Launch config (JSON)',
        'Line numbers',
        'Log message (empty: pause instead of logging)',
        'Mixed (unstage changes)',
        'New Branch…',
        'Next Dock Panel',
        'No debug session to restart',
        'Nothing to export',
        'Open as Text',
        'PlantUML path (or .jar)',
        'Previous Dock Panel',
        'Reset to %sha% (%mode%)',
        'Reset to %sha%?',
        'Reset to Commit…',
        'Restart Debugger',
        'Revert Commit',
        'Reverted %sha%',
        'SVG converter path',
        'Set %name% to',
        'Soft (keep staged changes)',
        'Sort Lines',
        'Sticky scroll',
        'Switch Branch…',
        'This debug adapter cannot edit variables',
        'Toggle Case',
        'latest',
        'not configured',
        'plantuml not found — install it, or set the "plantuml" setting',
        'rendering…',
        'the SVG could not be decoded',
        'the graph bitmap could not be decoded',
        '⏎/F3 next  Shift+F3 prev  Alt+X .*  Alt+W   Esc',
        '⏎/F3 next  Shift+F3 prev  Tab field  Alt+R replace  Alt+A all  Alt+X .*  Alt+W   Esc',
    ];

    public function testEveryUiStringLiteralReachesTheEnglishCatalogOrTheShrinkList(): void
    {
        $ids = array_values(array_unique([...$this->transLiterals(), ...$this->labelLiterals()]));
        $missing = array_values(array_diff($ids, $this->englishSources()));
        sort($missing);

        $gaps = self::KNOWN_GAPS;
        sort($gaps);
        $this->assertSame(
            $gaps,
            $missing,
            'untranslated UI literals changed: a new one must be added to messages.en.xlf (and the six targets), a translated one must leave KNOWN_GAPS',
        );
    }

    /**
     * Every literal-string first argument of a ->trans() call in src/ and the
     * bundled plugins' src/. Dynamic ids cannot be collected statically; the
     * two big dynamic-id tables are covered by {@see labelLiterals()}.
     *
     * @return list<string>
     */
    private function transLiterals(): array
    {
        $ids = [];
        foreach ($this->phpFiles() as $path) {
            $tokens = token_get_all((string) file_get_contents($path));
            $count = \count($tokens);
            for ($i = 1; $i < $count; ++$i) {
                $token = $tokens[$i];
                if (!(\is_array($token) && \T_STRING === $token[0] && 'trans' === $token[1])) {
                    continue;
                }
                $previous = $tokens[$i - 1];
                if (!(\is_array($previous) && \in_array($previous[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], strict: true))) {
                    continue;
                }
                $j = $this->nextMeaningful($tokens, $i + 1);
                if ('(' !== $tokens[$j]) {
                    continue;
                }
                $j = $this->nextMeaningful($tokens, $j + 1);
                $argument = $tokens[$j];
                if (!(\is_array($argument) && \T_CONSTANT_ENCAPSED_STRING === $argument[0])) {
                    continue;
                }
                $k = $this->nextMeaningful($tokens, $j + 1);
                if (',' === $tokens[$k] || ')' === $tokens[$k]) {
                    $ids[] = stripcslashes(substr($argument[1], 1, -1));
                }
            }
        }

        return $ids;
    }

    /**
     * The 'label' => '...' entries of the two catalogs-as-data translated at
     * render time with dynamic ids: the action catalog and the settings
     * descriptor table.
     *
     * @return list<string>
     */
    private function labelLiterals(): array
    {
        $labels = [];
        foreach (['/src/Config/ActionCatalog.php', '/src/Settings/SettingsController.php'] as $file) {
            $source = (string) file_get_contents(self::ROOT.$file);
            $matches = [];
            preg_match_all("/'label' => '((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);
            foreach ($matches[1] as $label) {
                $labels[] = stripcslashes($label);
            }
        }

        return $labels;
    }

    /** @return list<string> the en catalog's <source> texts */
    private function englishSources(): array
    {
        $xml = simplexml_load_file(self::ROOT.'/translations/messages.en.xlf');
        self::assertNotFalse($xml, 'messages.en.xlf is not valid XML');
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

    /** @return list<string> every .php file under src/ and plugins/<name>/src/ */
    private function phpFiles(): array
    {
        $roots = [self::ROOT.'/src', ...(glob(self::ROOT.'/plugins/*/src') ?: [])];
        $files = [];
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getPathname(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function nextMeaningful(array $tokens, int $index): int
    {
        $count = \count($tokens);
        while ($index < $count && \is_array($tokens[$index]) && \T_WHITESPACE === $tokens[$index][0]) {
            ++$index;
        }

        return $index;
    }
}
