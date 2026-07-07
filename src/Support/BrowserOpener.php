<?php

declare(strict_types=1);

namespace Porta\Support;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Resolves the platform command that opens a URL in the default browser.
 * Pure command resolution only — spawning is the caller's job (AsyncProcess),
 * so this stays exhaustively testable per OS family via PATH manipulation.
 */
final class BrowserOpener
{
    /**
     * The argv to open $url on $osFamily (default: this machine), or null
     * when no opener executable is available.
     *
     * @return list<string>|null
     */
    public static function command(string $url, ?string $osFamily = null): ?array
    {
        $osFamily ??= \PHP_OS_FAMILY;

        if ('Windows' === $osFamily) {
            // `start` is a cmd builtin; the empty string is the window title.
            return ['cmd', '/c', 'start', '', $url];
        }

        $opener = new ExecutableFinder()->find('Darwin' === $osFamily ? 'open' : 'xdg-open');

        return null === $opener ? null : [$opener, $url];
    }
}
