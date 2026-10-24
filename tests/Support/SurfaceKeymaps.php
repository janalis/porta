<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Assert;
use Porta\Plugin\Runtime\PluginManifest;

/**
 * Every surface's own key map, found by walking core and the bundled plugins:
 * the keymap gates read it here, so a new surface is swept in by all of them
 * the day it is written.
 */
final class SurfaceKeymaps
{
    /**
     * Every class ANYWHERE in the tree with a `getDefaultKeybindings()`, mapped
     * to what it returns.
     *
     * The framework's trait declares it `protected static`, so reflection is
     * the only way in from outside — and a public accessor added so a test
     * could ask would be test-only code in `src`.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function all(): array
    {
        $root = \dirname(__DIR__, levels: 2);
        $maps = [];
        foreach (self::sourceRoots($root) as $prefix => $directory) {
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
                if ('php' !== $file->getExtension() || !\str_contains((string) \file_get_contents($file->getPathname()), 'function getDefaultKeybindings')) {
                    continue;
                }
                // The EDITOR is not one of these surfaces. Its map is
                // {@see EditorKeymap::defaults()}, whose twenty-two micro-bindings
                // (arrows, shift+arrows, ctrl+home) are undocumented ON PURPOSE —
                // see this class's docblock — and it has its own gate above,
                // narrowed to the verbs a menu advertises. Sweeping it in here
                // would silently re-open a decision that was made and argued.
                if (\str_ends_with($file->getPathname(), '/Widget/CodeEditorWidget.php')) {
                    continue;
                }
                // FQCN is determined by path — a rule this repository states and
                // the arch gate enforces, so the walk can name the class.
                $relative = \substr($file->getPathname(), \strlen($directory.'/'), length: -4);
                /** @var class-string $class */
                $class = $prefix.\str_replace(search: '/', replace: '\\', subject: $relative);
                /** @var array<string, list<string>> $keys */
                $keys = Probe::invokeStatic($class, 'getDefaultKeybindings');
                $maps[$class] = $keys;
            }
        }

        Assert::assertNotSame([], $maps, 'the walk found no keymaps at all, which means it is broken rather than clean');

        return $maps;
    }

    /**
     * Where key-binding classes can live, as namespace prefix => directory.
     *
     * `src/` is one of these and NOT the only one: a bundled plugin binds keys
     * through the same trait, and the git view and the graph viewer are whole
     * surfaces reached only by their own keys. Walking `src/` alone made both
     * of them invisible to a gate whose whole promise is that nothing is —
     * which is this class's own lesson (a hard-coded list is a checklist the
     * next surface walks past) applied one directory out.
     *
     * The plugins' prefixes are read from their MANIFESTS, the same source the
     * autoloader uses, so a plugin cannot be missed by being spelled
     * differently and a new one is swept in the day it is added.
     *
     * @return array<string, string>
     */
    public static function sourceRoots(string $root): array
    {
        $roots = ['Porta\\' => $root.'/src'];
        $manifests = \glob($root.'/plugins/*/plugin.php');
        foreach (false === $manifests ? [] : $manifests as $pluginFile) {
            foreach (PluginManifest::beside($pluginFile)->autoloadDirs() as $prefix => $directories) {
                foreach ($directories as $directory) {
                    if (!\is_dir($directory)) {
                        continue;
                    }
                    $roots[$prefix] = \rtrim($directory, characters: '/');
                }
            }
        }

        return $roots;
    }
}
