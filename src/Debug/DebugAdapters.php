<?php

declare(strict_types=1);

namespace Porta\Debug;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The per-language debug-adapter registry, seeded from .porta.json's
 * `languages.<id>.debug` blocks and updated live by the settings screen.
 * Purely config-driven in v1 — no auto-detection (the seam for it is here).
 */
final class DebugAdapters
{
    /** @var array<string, DebugConfig> languageId => config */
    private array $configs = [];

    /**
     * @param array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string}> $languages IdeConfig's per-language overrides
     */
    public function __construct(#[Autowire('%env(json:PORTA_LANGUAGES)%')] array $languages)
    {
        foreach ($languages as $languageId => $entry) {
            $debug = $entry['debug'] ?? [];
            if (($debug['adapter'] ?? null) !== null) {
                $runCommand = $entry['runCommand'] ?? '';
                $runCommandArgv = '' !== $runCommand ? self::split($runCommand) : [];
                $this->configs[$languageId] = new DebugConfig(self::split($debug['adapter']), $debug['launch'] ?? [], $runCommandArgv);
            }
        }
    }

    /** The adapter configured for a language, or null when there is none. */
    public function adapterFor(string $languageId): ?DebugConfig
    {
        return $this->configs[$languageId] ?? null;
    }

    /** The configured adapter command as one string ('' when unset), for the settings row. */
    public function adapterCommand(string $languageId): string
    {
        return implode(' ', ($this->configs[$languageId] ?? null)->adapterArgv ?? []);
    }

    /**
     * The configured launch overrides, for the settings row.
     *
     * @return array<string, mixed>
     */
    public function launchOverrides(string $languageId): array
    {
        return ($this->configs[$languageId] ?? null)->launch ?? [];
    }

    /**
     * Record a settings edit: a blank command clears the language's adapter.
     *
     * @param array<string, mixed> $launch
     */
    public function set(string $languageId, string $adapterCommand, array $launch): void
    {
        $adapterCommand = trim($adapterCommand);
        if ('' === $adapterCommand) {
            unset($this->configs[$languageId]);

            return;
        }
        // Preserve the language's runCommand (edited in the language section, not here).
        $runCommandArgv = ($this->configs[$languageId] ?? null)->runCommandArgv ?? [];
        $this->configs[$languageId] = new DebugConfig(self::split($adapterCommand), $launch, $runCommandArgv);
    }

    /** @return list<string> */
    private static function split(string $command): array
    {
        $parts = preg_split('/\s+/', trim($command));

        return array_values(array_filter(false === $parts ? [] : $parts));
    }
}
