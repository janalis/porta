<?php

declare(strict_types=1);

namespace Porta\Highlight\Json;

use Tempest\Highlight\Languages\Json\JsonLanguage as TempestJsonLanguage;

/**
 * JSON with comments — the dialect `tsconfig.json`, `.vscode/*.json` and
 * `.jsonc` files are written in.
 *
 * The same rules as {@see JsonLanguage} plus `//` and block comments. Tempest
 * has no `jsonc` language of its own, so this registers under a new name rather
 * than overriding one.
 *
 * `.json5` maps here too. It is an approximation: JSON5's unquoted keys, single
 * quotes and trailing commas are not modelled, so an unquoted key renders
 * uncolored rather than wrong. Comments and quoted values — the bulk of a real
 * file — highlight correctly.
 */
final class JsoncLanguage extends TempestJsonLanguage
{
    public function getName(): string
    {
        return 'jsonc';
    }

    public function getPatterns(): array
    {
        return [...JsonPatterns::base(), ...JsonPatterns::core(), ...JsonPatterns::comments()];
    }
}
