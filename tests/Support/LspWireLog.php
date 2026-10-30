<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/** What a test reads back from the log {@see logging-lsp-server.php} keeps of the wire, one JSON message a line. */
final class LspWireLog
{
    /**
     * Every `initialize` the server logged, oldest first: whether it carried
     * `initializationOptions`, and what they were.
     *
     * @return list<array<string, mixed>|null>
     */
    public static function initializeOptions(string $log): array
    {
        if (!\is_file($log)) {
            return [];
        }
        $options = [];
        $lines = \explode("\n", (string) \file_get_contents($log));
        // Read while the server appends: what follows the last newline is a
        // line not yet whole — CI once caught one half-written, and its JSON
        // threw. It is read on the next look, once its newline is there.
        \array_pop($lines);
        foreach (\array_filter($lines) as $line) {
            /** @var array{method?: string, params?: array{initializationOptions?: array<string, mixed>}}|null $message */
            $message = \json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR);
            if (\is_array($message) && 'initialize' === ($message['method'] ?? null)) {
                $options[] = $message['params']['initializationOptions'] ?? null;
            }
        }

        return $options;
    }
}
