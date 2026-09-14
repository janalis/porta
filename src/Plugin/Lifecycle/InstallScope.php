<?php

declare(strict_types=1);

namespace Porta\Plugin\Lifecycle;

use Porta\Config\ConfigScope;

/**
 * Where an installed plugin lives: under the project, under the global home,
 * or bundled with Porta itself.
 *
 * Deliberately not {@see ConfigScope}, which it overlaps: there is no embedded
 * CONFIG, so widening that enum would hand every config consumer a case none of
 * them can reach. The spellings match, so {@see self::of()} is the bridge from
 * the settings screen's scope, and `->value` still keys the settings rows and
 * names the translated scope label.
 */
enum InstallScope: string
{
    case Project = 'project';
    case Global = 'global';
    case Embedded = 'embedded';

    /** The install target for the config scope the settings screen is pinned to. */
    public static function of(ConfigScope $scope): self
    {
        return match ($scope) {
            ConfigScope::Project => self::Project,
            ConfigScope::Global => self::Global,
        };
    }
}
