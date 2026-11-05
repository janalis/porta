<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * A value as a comparison or array key — "did the watchers change", "which
 * registration is this" — built by JSON-encoding it.
 */
final class JsonKey
{
    /**
     * $value's key. A key, not a payload: a value that cannot be encoded maps
     * to the same empty key rather than throwing, which for a change check
     * means "unchanged" and for a registry means one shared slot.
     */
    public static function of(mixed $value): string
    {
        // @noinspection JsonEncodingApiUsageInspection -- a key, not a payload: see the docblock
        return (string) \json_encode($value);
    }
}
