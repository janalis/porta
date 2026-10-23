<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Lsp\LspClientInterface;
use Porta\Support\Decoded;

/**
 * What one server said during an {@see LspProbeCommand} run, channel by
 * channel: the lines its client logged, the messages it sent, and the last
 * diagnostics it published for each file.
 */
final class LspProbeReport
{
    /** The server's client — running, or stopped when it failed to start. */
    public ?LspClientInterface $client = null;

    /** @var list<string> */
    public private(set) array $logged = [];

    /** @var list<array{0: int, 1: string}> `MessageType` and text, log and show messages alike */
    public private(set) array $messages = [];

    /**
     * The LAST set published per file: a server publishes in stages, and an
     * empty list means "nothing any more", so only the final word counts.
     *
     * @var array<string, list<array{line: int, severity: int, code: string, message: string}>> uri => diagnostics
     */
    public private(set) array $published = [];

    public function log(string $line): void
    {
        $line = \trim($line);
        if ('' !== $line) {
            $this->logged[] = $line;
        }
    }

    /** @param array<array-key, mixed> $params `window/logMessage` or `window/showMessage` */
    public function message(array $params): void
    {
        $this->messages[] = [self::int($params['type'] ?? null, default: 1), self::string($params['message'] ?? null)];
    }

    /** @param array<array-key, mixed> $params `textDocument/publishDiagnostics` */
    public function diagnostics(array $params): void
    {
        $uri = self::string($params['uri'] ?? null);
        if ('' === $uri) {
            return;
        }
        $kept = [];
        foreach (Decoded::objects($params['diagnostics'] ?? null) as $diagnostic) {
            $kept[] = [
                'line' => self::int($diagnostic['range']['start']['line'] ?? null, default: 0),
                'severity' => self::int($diagnostic['severity'] ?? null, default: 1),
                // A code is a string or a number, by the spec.
                'code' => \is_int($diagnostic['code'] ?? null) ? (string) $diagnostic['code'] : self::string($diagnostic['code'] ?? null),
                'message' => self::string($diagnostic['message'] ?? null),
            ];
        }
        if ([] === $kept) {
            unset($this->published[$uri]);

            return;
        }
        $this->published[$uri] = $kept;
    }

    private static function int(mixed $value, int $default): int
    {
        return \is_int($value) ? $value : $default;
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
