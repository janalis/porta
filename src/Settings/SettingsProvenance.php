<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Container\AsIdeService;
use Porta\Event\SettingsRowsStale;
use Porta\Support\JsonFile;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Which file a shown value actually came from.
 *
 * With one config file the question never arose. With two it is the first
 * thing a confused user asks: the Settings screen shows `light`, the global
 * file says `light`, and the project file quietly says `dark` — so why is the
 * editor dark? The answer is a tag on the row.
 *
 * Computed by re-reading both files rather than carried through the config
 * pipeline. That is deliberate: no new `$_ENV` channel, no new `IdeConfig`
 * property, no risk to the container's cache key — and, because it reads at
 * the point of use, it is correct in the SAME frame as a write rather than one
 * boot later. The cost is one `is_file` and one decode per file, memoised for
 * as long as the row set stands.
 */
#[AsIdeService]
final class SettingsProvenance
{
    /** @var array<string, array<array-key, mixed>|null>|null decoded file per scope, null until read */
    private ?array $files = null;

    public function __construct(
        private readonly ConfigFiles $paths,
    ) {}

    /**
     * Which file the effective value at $path comes from, or null when neither
     * sets it — the value is a default, which needs no tag.
     *
     * `$path` is the JSON key path — `['settings', 'theme']`,
     * `['languages', 'php', 'indentStyle']`, `['plugins', 'git', 'path']`.
     *
     * Two accessors rather than one call returning both, because a row is
     * built as one named-argument expression and PHP will not let a spread
     * follow named arguments. The file reads behind them are memoised, so the
     * second call costs an array walk.
     *
     * @param list<string> $path
     */
    public function originOf(array $path): ?ConfigScope
    {
        if (null !== $this->valueAt(ConfigScope::Project, $path)) {
            return ConfigScope::Project;
        }

        return null === $this->valueAt(ConfigScope::Global, $path) ? null : ConfigScope::Global;
    }

    /**
     * Whether the project file's value at $path hides a global one. False
     * whenever the project does not set it — there is nothing doing the
     * hiding.
     *
     * @param list<string> $path
     */
    public function shadowsGlobal(array $path): bool
    {
        return null !== $this->valueAt(ConfigScope::Project, $path) && null !== $this->valueAt(ConfigScope::Global, $path);
    }

    /**
     * Forget what the files said, so the next question re-reads them.
     *
     * Called after a WRITE. Nothing else notices one: `SettingsRowsStale` is
     * dispatched when the row SET moves — a plugin installed, enabled or
     * removed — and a value changing is not that. Without this the tags are
     * computed at the first row build and never move again, so saving to the
     * global file leaves the row still claiming the value is unset.
     *
     * Dropped rather than re-read: the next row build asks, and a write nobody
     * is looking at should not pay for two decodes.
     */
    public function invalidate(): void
    {
        $this->files = null;
    }

    /** A plugin came or went, so the row set — and its files — moved. */
    #[AsEventListener]
    public function onSettingsRowsStale(SettingsRowsStale $event): void
    {
        $this->invalidate();
    }

    /**
     * The raw value a scope's file holds at $path, or null when it holds none.
     *
     * @param list<string> $path
     */
    private function valueAt(ConfigScope $scope, array $path): mixed
    {
        $node = $this->file($scope);
        foreach ($path as $segment) {
            if (null === $node || !\array_key_exists($segment, $node)) {
                return null;
            }
            /** @var mixed $value */
            $value = $node[$segment];
            if (!\is_array($value)) {
                // A leaf. Only the last segment may reach one; a shorter path
                // hitting a scalar means the file's shape is not what the
                // caller assumed, and neither answer is "this key is set".
                return $segment === $path[\count($path) - 1] ? $value : null;
            }
            $node = $value;
        }

        return $node;
    }

    /** @return array<array-key, mixed>|null */
    private function file(ConfigScope $scope): ?array
    {
        if (null === $this->files) {
            $this->files = [];
            foreach (ConfigScope::cases() as $case) {
                $path = $this->paths->path($case);
                $this->files[$case->value] = null === $path ? null : JsonFile::read($path);
            }
        }

        return $this->files[$scope->value] ?? null;
    }
}
