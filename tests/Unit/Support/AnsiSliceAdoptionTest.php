<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\AnsiSlice;

/**
 * The tripwire behind {@see AnsiSlice}: the vendor slicers it wraps count a
 * combining mark as a column, so a new call to one of them brings the bug back
 * for every accented line it touches — silently, since plain text slices the
 * same either way.
 */
final class AnsiSliceAdoptionTest extends TestCase
{
    private const string CALL = '/AnsiUtils::(sliceByColumn|sliceWithWidth|truncateToWidth)\(/';

    /** @return list<string> */
    private static function sources(): array
    {
        $root = \dirname(__DIR__, levels: 3);
        $paths = [];
        foreach ([$root.'/src', ...((array) \glob($root.'/plugins/*/src'))] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) $dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                    continue;
                }
                $paths[] = $file->getPathname();
            }
        }

        return [...$paths, ...\array_map(strval(...), (array) \glob($root.'/plugins/*/plugin.php'))];
    }

    public function testNoCodeSlicesThroughTheVendorDirectly(): void
    {
        $root = \dirname(__DIR__, levels: 3).'/';
        $offenders = [];
        foreach (self::sources() as $path) {
            if (\str_ends_with($path, '/src/Support/AnsiSlice.php')) {
                continue;
            }
            if (1 === \preg_match(self::CALL, (string) \file_get_contents($path))) {
                $offenders[] = \str_replace($root, replace: '', subject: $path);
            }
        }

        $this->assertGreaterThan(500, \count(self::sources()), 'the scan reads the whole tree');
        $this->assertSame([], $offenders, 'slice through Porta\Support\AnsiSlice — the vendor call miscounts combining marks');
    }

    public function testTheScanWouldNoticeOne(): void
    {
        // The positive control: a scan that matched nothing reads exactly like
        // one that ran nothing.
        $this->assertMatchesRegularExpression(self::CALL, '$x = AnsiUtils::sliceByColumn($line, 0, 3);');
        $this->assertMatchesRegularExpression(self::CALL, 'AnsiUtils::truncateToWidth($t, 9)');
        $this->assertDoesNotMatchRegularExpression(self::CALL, 'AnsiUtils::sliceToWidth($line, 2, 3)', 'sliceToWidth is not affected');
    }
}
