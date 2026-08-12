<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Ide;
use Porta\Overlay\State\ToastCenter;
use Porta\Tests\Support\Services;
use Porta\Workbench\Notifier;

/**
 * Reads the live toast stack off a booted Ide — the test-side counterpart of
 * the status-bar `$flash` reflection the suites used before notifications
 * became corner toasts. Asserting on the CENTER's messages (not the rendered
 * card) keeps assertions independent of the card's wrap points.
 */
final class Toasts
{
    /** The newest live toast's message, or '' when none is showing. */
    public static function last(Ide $ide): string
    {
        $all = self::all($ide);

        return $all[0] ?? '';
    }

    /** @return list<string> live toast messages, newest first */
    public static function all(Ide $ide): array
    {
        $notifier = Services::resolve($ide, Notifier::class);
        $center = Probe::object($notifier, 'toasts'); // src carries no test-only getter
        \assert($center instanceof ToastCenter);

        return array_map(static fn ($t): string => $t->message, $center->toasts());
    }
}
