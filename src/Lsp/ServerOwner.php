<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * Which server offered a thing, carried inside the thing itself.
 *
 * A completion item, a code action and a code lens are each offered by ONE
 * server and each have a second round trip — `completionItem/resolve`,
 * `codeAction/resolve`, `workspace/executeCommand` — that has to go back to
 * that server. With several servers on a language, sending it anywhere else is
 * at best an error response and at worst another server acting on an id that
 * means something different to it.
 *
 * The stamp rides in the payload because that is what survives the trip
 * through the UI: the popup hands the raw item back, the quick-fix list hands
 * the raw action back, and neither has anywhere else to put a note. It is
 * taken off again before the payload goes on the wire — the LSP spec says an
 * item is handed back to resolve unchanged, and a field of ours arriving at a
 * server is at best ignored and at worst rejected.
 */
final class ServerOwner
{
    /** Underscored and namespaced, because it travels inside a structure the spec owns. */
    private const string KEY = '_portaOwner';

    /**
     * Callers reindex what they pass here (`array_values`) so the declared
     * `list` is true rather than assumed. Nothing downstream can tell the
     * difference — the keys are never read — so mutating that reindex away
     * produces an equivalent mutant, not a coverage gap. It stays because a
     * type that is only true by luck is the kind that stops being true.
     *
     * @param list<array<string, mixed>> $payloads
     *
     * @return list<array<string, mixed>>
     */
    public static function stampAll(array $payloads, string $owner): array
    {
        return \array_map(static function (array $payload) use ($owner): array {
            $payload[self::KEY] = $owner;

            return $payload;
        }, $payloads);
    }

    /**
     * The owner and the payload as its server last saw it.
     *
     * An unstamped payload answers '', which routes to whoever serves the
     * active file — the behaviour from before there was ever more than one,
     * and what a caller that never went through {@see stampAll()} should get.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function take(array $payload): array
    {
        /** @var mixed $owner */
        $owner = $payload[self::KEY] ?? null;
        unset($payload[self::KEY]);

        return [\is_string($owner) ? $owner : '', $payload];
    }
}
