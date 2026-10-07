<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

/**
 * A floating window's close button, as the desktop it runs on draws one: on
 * macOS a red `(✖)` leading the title bar, where a window's controls have
 * always sat; everywhere else a `[✖]` at its right end.
 */
enum CloseButton
{
    case Mac;
    case Standard;

    /** The platform's convention, from its `PHP_OS_FAMILY`. */
    public static function forOs(string $family): self
    {
        return 'Darwin' === $family ? self::Mac : self::Standard;
    }

    /** The columns the button draws, every one of them a press that closes: the cross and its brackets. */
    public const int COLUMNS = 3;

    /** Whether the button leads the title bar rather than ending it. */
    public function leads(): bool
    {
        return self::Mac === $this;
    }
}
