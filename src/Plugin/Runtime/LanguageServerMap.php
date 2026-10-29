<?php

declare(strict_types=1);

namespace Porta\Plugin\Runtime;

use Porta\Plugin\Api\ServerNotFound;
use Porta\Plugin\Api\ServerRole;

/**
 * Turns the language-server declarations plugins made into the map the LSP
 * layer launches from: `languageId => [its own server, …the ones beside it]`.
 *
 * Its own class because deciding WHO serves a language is a policy with three
 * rules that interact — first primary wins, supplementary joins, a lone
 * supplementary is promoted — and {@see PluginManager} is already the place
 * every other kind of registration lands. Resolution is also the half that
 * runs a plugin's closures, so it is the half that has to survive one of them
 * throwing.
 */
final readonly class LanguageServerMap
{
    /** @param \Closure(string, \Throwable): void $reportFailure plugin name, and what its resolver threw */
    public function __construct(
        private \Closure $reportFailure,
    ) {}

    /**
     * @param list<array{plugin: string, languageId: string, name: string, role: ServerRole, resolve: \Closure(): (list<string>|ServerNotFound|null), initOptions: (\Closure(): array<string, mixed>)|null}> $declarations in load order
     *
     * @return array<string, non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>>
     */
    public function resolve(array $declarations): array
    {
        $primary = [];
        $beside = [];
        foreach ($declarations as $declaration) {
            $language = $declaration['languageId'];
            $isPrimary = ServerRole::Supplementary !== $declaration['role'];
            if ($isPrimary && \array_key_exists($language, $primary)) {
                continue; // an earlier provider already IS this language's server
            }
            $entry = $this->launch($declaration);
            if (null === $entry) {
                continue;
            }
            if ($isPrimary) {
                $primary[$language] = $entry;

                continue;
            }
            $beside[$language][] = $entry;
        }

        $servers = [];
        foreach ([...\array_keys($primary), ...\array_keys($beside)] as $language) {
            // Nothing claims this language outright, so the first
            // supplementary claim leads rather than being dropped — which is
            // what keeps symfony-lsp serving php on a project with no PHP
            // server enabled.
            $ordered = [...(\array_key_exists($language, $primary) ? [$primary[$language]] : []), ...($beside[$language] ?? [])];
            \assert([] !== $ordered, description: 'a language is only listed because a resolver answered for it');
            $servers[$language] = self::withoutDuplicates($ordered);
        }

        return $servers;
    }

    /**
     * The declared servers whose binary is not there, by language — a
     * resolver that throws is the failure {@see resolve()} reports, not this.
     *
     * @param list<array{plugin: string, languageId: string, name: string, role: ServerRole, resolve: \Closure(): (list<string>|ServerNotFound|null), initOptions: (\Closure(): array<string, mixed>)|null}> $declarations
     *
     * @return array<string, list<string>>
     */
    public function missing(array $declarations): array
    {
        $missing = [];
        foreach ($declarations as $declaration) {
            try {
                $command = $declaration['resolve']();
            } catch (\Throwable) {
                continue;
            }
            if ($command instanceof ServerNotFound) {
                $missing[$declaration['languageId']][] = $declaration['name'];
            }
        }

        return $missing;
    }

    /**
     * One declaration resolved to a launch entry, or null when its binary is
     * not available — or its resolver threw, which is reported and treated as
     * unavailable rather than taking the whole map with it.
     *
     * @param array{plugin: string, languageId: string, name: string, role: ServerRole, resolve: \Closure(): (list<string>|ServerNotFound|null), initOptions: (\Closure(): array<string, mixed>)|null} $declaration
     *
     * @return array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}|null
     */
    private function launch(array $declaration): ?array
    {
        $options = [];
        try {
            $command = $declaration['resolve']();
            $options = null === $declaration['initOptions'] ? [] : $declaration['initOptions']();
            // Validate encodability HERE, inside the per-plugin guard: the
            // session pool json-encodes options into its session signature on
            // the render path, where a NAN/INF/bad-UTF-8 value from a plugin
            // would otherwise become a recurring render fault instead of one
            // reported plugin failure.
            \json_encode($options, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            ($this->reportFailure)($declaration['plugin'], $error);
            $command = null;
        }
        if (null === $command || [] === $command || $command instanceof ServerNotFound) {
            return null; // its resolver threw, not this server's to serve here, or its binary is not there
        }
        $entry = ['command' => \array_values($command), 'name' => $declaration['name']];
        if ([] !== $options) {
            $entry['initializationOptions'] = $options;
        }

        return $entry;
    }

    /**
     * Entries with a distinct launch signature, first occurrence kept — a
     * plugin that registered the same argv twice, or two plugins resolving to
     * one binary, would otherwise have it launched, fed and asked twice over.
     *
     * @param non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}> $entries
     *
     * @return non-empty-list<array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>
     */
    private static function withoutDuplicates(array $entries): array
    {
        $unique = [$entries[0]];
        $seen = [self::signatureOf($entries[0]) => true];
        foreach (\array_slice($entries, offset: 1) as $entry) {
            $signature = self::signatureOf($entry);
            if (\array_key_exists($signature, $seen)) {
                continue;
            }
            $seen[$signature] = true;
            $unique[] = $entry;
        }

        return $unique;
    }

    /**
     * What makes two launch entries the same process to the session pool —
     * argv plus initializationOptions, mirroring
     * {@see \Porta\Lsp\LspSessionPool::signature()}.
     *
     * @param array{command: list<string>, name: string, initializationOptions?: array<string, mixed>} $entry
     */
    private static function signatureOf(array $entry): string
    {
        return \implode("\0", $entry['command'])."\0".\json_encode($entry['initializationOptions'] ?? null, \JSON_THROW_ON_ERROR);
    }
}
