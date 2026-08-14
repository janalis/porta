<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every `Foo::class` in the tree names a class that exists.
 *
 * `X::class` is a STRING, resolved at compile time from the file's imports and
 * namespace, and PHP never checks that anything answers to it. So a class can be
 * deleted while a reference to it goes on compiling, running and — this is the
 * part that costs something — passing.
 *
 * Two real cases, both found by the scan this test is:
 *
 * - `ContainerAliasesTest` pinned "a multi-implementation interface must not be
 *   auto-aliased" against `FullScreenView::class`, deleted when views became
 *   string-keyed. It asserted that the container has no alias for a class the
 *   container has never heard of. True, and true for the wrong reason, for two
 *   rounds. The file's IMPORT had been updated; the usage had not.
 * - `IdeHelpersTest` carried a `localHistory()` helper resolving
 *   `LocalHistoryController`, deleted when local history became a plugin. Never
 *   called, so never fatal.
 *
 * No other gate sees this. `composer analyze` does not report on `tests/`, and
 * `unused-method` is a help-level finding that lives in the standing lint tail.
 *
 * Comments AND string literals are stripped first. A class name in either is
 * data, not a reference: `$locator->get(X::class)` in a docblock explains a
 * mechanism, and `"\$k = Foo::class;"` in `BreadcrumbScannerTest` is a fixture
 * the scanner under test is pointed at. Neither is something PHP would resolve.
 */
final class GhostClassReferenceTest extends TestCase
{
    public function testEveryClassConstantReferenceResolves(): void
    {
        $ghosts = [];
        foreach ($this->sources() as $path) {
            $source = (string) file_get_contents($path);
            $code = self::stripCommentsAndStrings($source);
            preg_match('/^namespace ([A-Za-z0-9\\\\]+);/m', $code, $namespace);
            $aliases = self::aliases($code);
            $hits = [];
            preg_match_all('/(?<![\$>:\w\\\\])(\\\\?[A-Z][A-Za-z0-9_\\\\]*)::class/', $code, $hits, \PREG_SET_ORDER);
            foreach ($hits as [, $name]) {
                $fqcn = self::resolve($name, $aliases, $namespace[1] ?? '');
                if (self::exists($fqcn)) {
                    continue;
                }
                $ghosts[] = str_replace(\dirname(__DIR__, levels: 2).'/', replace: '', subject: $path).': '.$fqcn;
            }
        }

        $this->assertSame([], array_values(array_unique($ghosts)), '`::class` naming something that does not exist — a reference PHP will never check');
    }

    public function testTheScanWouldNoticeOne(): void
    {
        // The positive control, because the assertion above is a negative and a
        // scan that found nothing looks exactly like a scan that ran nothing.
        $aliases = ['Role' => 'Porta\\Ui\\Role'];

        $this->assertTrue(self::exists(self::resolve('Role', $aliases, 'Porta\\Tests')), 'an imported class resolves');
        $this->assertFalse(self::exists(self::resolve('NoSuchThingAnywhere', $aliases, 'Porta\\Tests')), 'and an invented one does not');
        $this->assertSame('Porta\\Tests\\Local', self::resolve('Local', [], 'Porta\\Tests'), 'an unimported name is namespace-relative');
    }

    /** @return list<string> */
    private function sources(): array
    {
        $root = \dirname(__DIR__, levels: 2);
        $paths = [];
        foreach (['src', 'tests', 'plugins'] as $dir) {
            $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($tree as $entry) {
                \assert($entry instanceof \SplFileInfo, description: 'the iterator yields file entries');
                $path = $entry->getPathname();
                // Vendored code is not ours, and the fixtures are deliberately
                // malformed — both would report on someone else's decisions.
                if (!$entry->isFile() || 'php' !== $entry->getExtension() || str_contains($path, '/vendor/') || str_contains($path, '/fixtures/')) {
                    continue;
                }
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @return array<string, string> alias (or bare name) => FQCN */
    private static function aliases(string $code): array
    {
        $aliases = [];
        $uses = [];
        preg_match_all('/^use ([A-Za-z0-9\\\\]+)(?: as ([A-Za-z0-9]+))?;/m', $code, $uses, \PREG_SET_ORDER);
        foreach ($uses as $use) {
            $parts = explode('\\', $use[1]);
            $aliases[$use[2] ?? end($parts)] = $use[1];
        }

        return $aliases;
    }

    /** @param array<string, string> $aliases */
    private static function resolve(string $name, array $aliases, string $namespace): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, characters: '\\');
        }
        $head = explode('\\', $name)[0];
        if (array_key_exists($head, $aliases)) {
            return $aliases[$head].substr($name, offset: \strlen($head));
        }

        return '' === $namespace ? $name : $namespace.'\\'.$name;
    }

    private static function exists(string $fqcn): bool
    {
        // `self`/`static`/`parent` never reach here — the pattern requires an
        // upper-case first letter, and PHP's magic ones are lower-case.
        return class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn) || trait_exists($fqcn);
    }

    /** Only CODE names a class; a comment or a string literal holds text that looks like one. */
    private static function stripCommentsAndStrings(string $source): string
    {
        $skip = [\T_COMMENT, \T_DOC_COMMENT, \T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE, \T_INLINE_HTML];
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)) {
                $out .= $token;

                continue;
            }
            if (\in_array($token[0], $skip, strict: true)) {
                continue;
            }
            $out .= $token[1];
        }

        return $out;
    }
}
