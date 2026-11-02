<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\DocsTree;

/**
 * Every bundled plugin is also a repository of its own: `.github/workflows/split.yml`
 * pushes `plugins/<name>` to `janalis/porta-<name>` on every push and tag, and
 * Packagist publishes it from there. What a split repository needs must then
 * be in the subtree itself — the split copies nothing from the root.
 *
 * - a `janalis/porta-<name>` package name: the installer strips the `porta-`,
 *   so the package lands as `<name>` and shadows the bundled copy;
 * - the root's LICENSE, word for word;
 * - a README that says it is a read-only split, and the workflow that closes a
 *   pull request opened there with a pointer to this repository.
 */
final class BundledPluginSplitTest extends TestCase
{
    /** @var list<string> */
    private const array FILES = ['README.md', 'LICENSE', '.gitattributes', '.github/PULL_REQUEST_TEMPLATE.md', '.github/workflows/close-pull-request.yml'];

    public function testEveryBundledPluginCarriesWhatItsSplitRepositoryNeeds(): void
    {
        $root = \dirname(__DIR__, levels: 3);
        $license = (string) \file_get_contents($root.'/LICENSE');
        $plugins = DocsTree::plugins();
        $this->assertNotSame([], $plugins);

        $wrong = [];
        foreach (\array_keys($plugins) as $name) {
            $dir = $root.'/plugins/'.$name;
            foreach (\array_filter(self::FILES, static fn (string $file): bool => !\is_file($dir.'/'.$file)) as $file) {
                $wrong[] = $name.': no '.$file;
            }
            /** @var array{name?: mixed, type?: mixed} $manifest */
            $manifest = \is_file($dir.'/composer.json') ? \json_decode((string) \file_get_contents($dir.'/composer.json'), associative: true, flags: \JSON_THROW_ON_ERROR) : [];
            if ('janalis/porta-'.$name !== ($manifest['name'] ?? null) || 'porta-plugin' !== ($manifest['type'] ?? null)) {
                $wrong[] = $name.': composer.json must be "janalis/porta-'.$name.'", "type": "porta-plugin"';
            }
            if (\is_file($dir.'/LICENSE') && \file_get_contents($dir.'/LICENSE') !== $license) {
                $wrong[] = $name.': LICENSE differs from the root one';
            }
            if (\is_file($dir.'/README.md') && !\str_contains((string) \file_get_contents($dir.'/README.md'), 'read-only split of `plugins/'.$name.'`')) {
                $wrong[] = $name.': README.md does not say it is the read-only split of plugins/'.$name;
            }
        }

        $this->assertSame([], $wrong);
    }
}
