<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * A boot-time warning, translated only once a `Translator` exists — config
 * and plugin discovery run before the container does, so they cannot call
 * `trans()` themselves. `%placeholder%` keys match {@see \Porta\I18n\Translator::trans()}'s
 * ICU-free `%name%` convention.
 */
final readonly class ConfigWarning
{
    /** @param array<string, string> $params */
    public function __construct(
        public string $key,
        public array $params = [],
    ) {}
}
