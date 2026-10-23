<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Narrowing for what came out of `json_decode()` — a server's reply, a release
 * listing, a file someone else wrote — where nothing is the shape it should
 * be until it has been checked.
 */
final class Decoded
{
    /**
     * The entries of a decoded list that are themselves objects; anything else
     * in it — or a value that is no list at all — is dropped.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function objects(mixed $list): array
    {
        $objects = [];
        // @mago-expect analysis:mixed-assignment -- a decoded value, narrowed on the next line
        foreach (\is_array($list) ? $list : [] as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $objects[] = $entry;
        }

        return $objects;
    }
}
