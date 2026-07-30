<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

/**
 * What a plugin declares about ITSELF, in an optional `plugin.json` beside its
 * `plugin.php`:
 *
 * ```json
 * { "namespace": "Porta\\GraphViewer", "autoload": "src", "requires": ["image-viewer"] }
 * ```
 *
 * It is read **before any of the plugin's PHP is required**, and that ordering
 * is a requirement rather than a preference: `requires` decides LOAD ORDER, so
 * it cannot itself be discovered by loading. The namespace is registered with
 * {@see PluginAutoloader} for the same reason — a plugin's own classes must
 * resolve from the first line of its `plugin.php`.
 *
 * Every field is optional, and a plugin with no manifest at all behaves exactly
 * as it did before there was one: no classes of its own, no dependencies.
 */
final readonly class PluginManifest
{
    /**
     * @param string  $name      the plugin's directory name, which is its identity everywhere else
     * @param ?string $namespace the PSR-4 prefix its classes live under, when it ships any
     * @param string  $autoload  the directory that prefix maps to, relative to the plugin
     * @param list<string> $requires plugin names that must load, and load FIRST
     */
    public function __construct(
        public string $name,
        public string $directory,
        public ?string $namespace = null,
        public string $autoload = 'src',
        public array $requires = [],
    ) {}

    /** The absolute directory $namespace maps to, or null when the plugin ships no classes. */
    public function autoloadDir(): ?string
    {
        if (null === $this->namespace) {
            return null;
        }
        $dir = $this->directory.'/'.trim($this->autoload, characters: '/');

        return is_dir($dir) ? $dir : null;
    }

    /**
     * The manifest beside $pluginFile — the defaults when there is none, and
     * also when there is one that is unreadable or malformed: a plugin that
     * worked yesterday must not vanish because its metadata has a typo, and the
     * failure is visible either way (its classes will not resolve).
     */
    public static function beside(string $pluginFile): self
    {
        $directory = \dirname($pluginFile);
        $name = basename($directory);
        $path = $directory.'/plugin.json';
        /** @var array<array-key, mixed>|scalar|null $data */
        $data = is_file($path) ? json_decode((string) file_get_contents($path), associative: true) : null;
        if (!\is_array($data)) {
            return new self($name, $directory);
        }

        $namespace = \is_string($data['namespace'] ?? null) ? trim($data['namespace'], characters: '\\') : null;
        $autoload = \is_string($data['autoload'] ?? null) ? $data['autoload'] : 'src';
        $requires = [];
        /** @var mixed $required */
        foreach (\is_array($data['requires'] ?? null) ? $data['requires'] : [] as $required) {
            if (!\is_string($required) || '' === $required) {
                continue;
            }
            $requires[] = $required;
        }

        return new self($name, $directory, '' === $namespace ? null : $namespace, $autoload, $requires);
    }
}
