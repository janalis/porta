<?php

declare(strict_types=1);

namespace Porta\Custos;

use Porta\Plugin\Api\ServerNotFound;

/**
 * What the plugin file wires and this class decides: the `custos lsp` launch
 * argv, and the `initializationOptions` built from the plugin's settings.
 *
 * custos reads the project's own configuration (custos.json, then
 * composer.json) at the workspace root, and `initializationOptions` — the same
 * JSON shape as custos.json — override it key by key. So a setting left at
 * "the project decides" must not travel at all: an empty `php` would override
 * the project's target version with nothing. Only a value the user actually
 * chose is sent, and with none the handshake carries no options whatsoever.
 */
final class ServerConfig
{
    /** The comparisonStyle choice that sends nothing and leaves it to the project. */
    public const string PROJECT = 'project';

    /** @var non-empty-list<string> the comparisonStyle choices, the default first */
    public const array COMPARISON_STYLES = [self::PROJECT, 'regular', 'yoda'];

    /**
     * The launch argv, or {@see ServerNotFound} when there is no binary — which
     * leaves custos out rather than launching a broken process.
     *
     * @return list<string>|ServerNotFound
     */
    public function command(?string $binary): array|ServerNotFound
    {
        return null === $binary ? new ServerNotFound() : [$binary, 'lsp'];
    }

    /**
     * `custos fix`'s argv after the binary, for one file.
     *
     * `--config` is the project root because that is where the server reads
     * its config: left out, the CLI looks from the FILE's directory, and a
     * fix could follow different rules than the diagnostics that offered it.
     * `--php` and `--comparison-style` carry the same overrides the server
     * gets, under the same rule: only a value the user chose travels.
     *
     * @return list<string>
     */
    public function fixArguments(string $file, string $projectRoot, string $php, string $comparisonStyle): array
    {
        $flags = [];
        foreach ($this->initializationOptions($php, $comparisonStyle) as $key => $value) {
            $flags[] = 'php' === $key ? '--php' : '--comparison-style';
            $flags[] = $value;
        }

        return ['fix', '--config', $projectRoot, ...$flags, $file];
    }

    /**
     * The overrides the user chose, in custos.json's shape: `php` when the
     * target version is set, `comparisonStyle` when it is one custos knows.
     * Anything else — blank, "project", a hand-edited typo — is left to the
     * project's config rather than sent for custos to reject at initialize.
     *
     * @return array<string, string>
     */
    public function initializationOptions(string $php, string $comparisonStyle): array
    {
        $options = [];
        $php = \trim($php);
        if ('' !== $php) {
            $options['php'] = $php;
        }
        $style = \trim($comparisonStyle);
        if (self::PROJECT !== $style && \in_array($style, self::COMPARISON_STYLES, strict: true)) {
            $options['comparisonStyle'] = $style;
        }

        return $options;
    }
}
