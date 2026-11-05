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
     * The object (or list) $json holds; null for text that is not JSON, or
     * JSON that is a bare scalar. For what came from elsewhere — a peer's line
     * off the network, another program's output, a file someone else wrote —
     * where text that is not JSON reads as nothing there, it does not throw.
     *
     * @return array<array-key, mixed>|null
     */
    public static function json(string $json): ?array
    {
        // @mago-expect analysis:mixed-assignment -- narrowed on the next line
        // @noinspection JsonEncodingApiUsageInspection -- not JSON is the null answer; see the docblock
        $decoded = \json_decode($json, associative: true);

        return \is_array($decoded) ? $decoded : null;
    }

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
