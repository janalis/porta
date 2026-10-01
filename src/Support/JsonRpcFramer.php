<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Content-Length framing shared by the LSP and DAP wire protocols: each frame
 * is `Content-Length: N\r\n\r\n<N bytes of JSON>`. `feed()` is a stateful
 * incremental parser — bytes may arrive split or batched arbitrarily — that
 * drops corrupt header blocks and resyncs on the next one.
 *
 * The read buffer is capped: a peer that never sends a header (a wrapper
 * script spewing plain text) or declares an absurd Content-Length would
 * otherwise accumulate bytes without bound — the write side has
 * {@see NonBlockingWriter}'s limit, and this is its mirror.
 */
final class JsonRpcFramer
{
    /** Past any real LSP/DAP payload; a declaration above it is treated as corrupt. */
    public const int MAX_FRAME = 16_777_216;

    private string $buffer = '';

    /**
     * Consumed-prefix offset into {@see $buffer}: each extracted frame used to
     * `substr` the whole remainder, making a k-frame burst O(n·k). Frames now
     * advance this cursor and the buffer compacts ONCE per feed(), on both
     * return paths — so the offset is always 0 between calls and the overflow
     * cap keeps measuring only unconsumed bytes.
     */
    private int $offset = 0;

    /** @var (callable(string): void)|null */
    private $onOverflow;

    public function __construct(
        ?callable $onOverflow = null,
        private readonly int $maxFrame = self::MAX_FRAME,
    ) {
        $this->onOverflow = $onOverflow;
    }

    /** Encode one message as a wire frame. */
    public static function encode(array $message): string
    {
        $json = \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);

        return 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
    }

    /**
     * Append raw bytes and return every complete decoded message now available.
     *
     * @return list<array<array-key, mixed>>
     */
    public function feed(string $data): array
    {
        $this->buffer .= $data;

        $messages = [];
        while (true) {
            $headerEnd = \strpos($this->buffer, needle: "\r\n\r\n", offset: $this->offset);
            if (false === $headerEnd) {
                $this->compact();
                if (\strlen($this->buffer) > $this->maxFrame) {
                    // Not speaking the protocol at all: discard, resync on
                    // whatever header eventually arrives.
                    $this->overflow(\sprintf('%d buffered bytes with no frame header — discarded', \strlen($this->buffer)));
                    $this->buffer = '';
                }

                return $messages;
            }

            $headers = \substr($this->buffer, $this->offset, $headerEnd - $this->offset);
            $m = [];
            if (!\preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
                // Corrupt frame; drop the header block and resync.
                $this->overflow('a header block with no Content-Length — dropped');
                $this->offset = $headerEnd + 4;
                continue;
            }

            $length = (int) $m[1];
            if ($length > $this->maxFrame) {
                // An absurd declaration must not park the parser accumulating
                // "body" bytes forever — corrupt-header semantics instead.
                $this->overflow(\sprintf('declared Content-Length %d exceeds the %d cap — resyncing', $length, $this->maxFrame));
                $this->offset = $headerEnd + 4;
                continue;
            }
            $bodyStart = $headerEnd + 4;
            if (\strlen($this->buffer) < ($bodyStart + $length)) {
                $this->compact();

                return $messages; // wait for more data
            }

            $body = \substr($this->buffer, $bodyStart, $length);
            $this->offset = $bodyStart + $length;

            /** @var array<array-key, mixed>|scalar|null $message */
            $message = \json_decode($body, associative: true);
            if (!\is_array($message)) {
                // A Content-Length that was wrong reads part of a frame as
                // this one: what it held — maybe an answer something waits
                // for — is lost, and a trace of that is the least owed.
                $this->overflow(\sprintf('a %d-byte frame that is not a JSON object — dropped', $length));
                continue;
            }
            $messages[] = $message;
        }
    }

    /** Drop the consumed prefix — once per feed(), not once per frame. */
    private function compact(): void
    {
        if ($this->offset > 0) {
            $this->buffer = \substr($this->buffer, $this->offset);
            $this->offset = 0;
        }
    }

    private function overflow(string $reason): void
    {
        if (null !== $this->onOverflow) {
            ($this->onOverflow)($reason);
        }
    }
}
