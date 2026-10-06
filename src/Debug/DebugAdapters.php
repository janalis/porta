<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Support\Argv;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The per-language debug-adapter registry, seeded from .porta.json's
 * `languages.<id>.debug` blocks and updated live by the settings screen, with
 * plugin-contributed adapters ({@see DebugAdapterProvider}) filling the
 * languages that block does not name.
 *
 * Precedence is the user first, always: a hand-configured adapter is never
 * shadowed by a plugin's, and clearing the setting hands the language back to
 * whichever plugin claims it. Auto-DETECTION is still out — a plugin declaring
 * "I can debug PHP" is a claim, not a guess.
 */
final class DebugAdapters
{
    /** @var array<string, DebugConfig> languageId => config */
    private array $configs = [];

    /**
     * @param array<string, array{indentStyle?: string, indentSize?: int|string, debug?: array{adapter?: string, launch?: array<string, mixed>}, runCommand?: string}> $languages IdeConfig's per-language overrides
     */
    public function __construct(
        #[Autowire('%env(json:PORTA_LANGUAGES)%')]
        array $languages,
        private readonly ?DebugAdapterProvider $plugins = null,
    ) {
        foreach ($languages as $languageId => $entry) {
            $adapter = ($entry['debug'] ?? [])['adapter'] ?? null;
            if (null !== $adapter) {
                $runCommand = $entry['runCommand'] ?? '';
                $runCommandArgv = '' !== $runCommand ? self::split($runCommand) : [];
                $this->configs[$languageId] = new DebugConfig(self::split($adapter), $entry['debug']['launch'] ?? [], $runCommandArgv);
            }
        }
    }

    /**
     * The adapter for a language: the user's configuration first, then a
     * plugin's, then none.
     */
    public function adapterFor(string $languageId): ?DebugConfig
    {
        $configured = $this->configs[$languageId] ?? null;
        if (null !== $configured) {
            return $configured;
        }
        $argv = $this->plugins?->debugAdapters()[$languageId] ?? null;

        return null === $argv || [] === $argv ? null : new DebugConfig($argv, [], []);
    }

    /** The configured adapter command as one string ('' when unset), for the settings row. */
    public function adapterCommand(string $languageId): string
    {
        return \implode(' ', ($this->configs[$languageId] ?? null)->adapterArgv ?? []);
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
        $adapterCommand = \trim($adapterCommand);
        if ('' === $adapterCommand) {
            unset($this->configs[$languageId]);

            return;
        }
        // Preserve the language's runCommand (edited in the language section, not here).
        $runCommandArgv = ($this->configs[$languageId] ?? null)->runCommandArgv ?? [];
        $this->configs[$languageId] = new DebugConfig(self::split($adapterCommand), $launch, $runCommandArgv);
    }

    /**
     * Tokenize a configured adapter command with the shared quote-aware
     * tokenizer, so an adapter path containing spaces survives.
     *
     * @return list<string>
     */
    private static function split(string $command): array
    {
        return Argv::split($command);
    }
}
