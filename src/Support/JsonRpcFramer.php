<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Content-Length framing shared by the LSP and DAP wire protocols: each frame
 * is `Content-Length: N\r\n\r\n<N bytes of JSON>`. `feed()` is a stateful
 * incremental parser — bytes may arrive split or batched arbitrarily — that
 * drops corrupt header blocks and resyncs on the next one.
 */
final class JsonRpcFramer
{
    private string $buffer = '';

    /** Encode one message as a wire frame. */
    public static function encode(array $message): string
    {
        $json = json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);

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
            $headerEnd = strpos($this->buffer, "\r\n\r\n");
            if (false === $headerEnd) {
                return $messages;
            }

            $headers = substr($this->buffer, 0, $headerEnd);
            $m = [];
            if (!preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
                // Corrupt frame; drop the header block and resync.
                $this->buffer = substr($this->buffer, $headerEnd + 4);
                continue;
            }

            $length = (int) $m[1];
            $bodyStart = $headerEnd + 4;
            if (\strlen($this->buffer) < ($bodyStart + $length)) {
                return $messages; // wait for more data
            }

            $body = substr($this->buffer, $bodyStart, $length);
            $this->buffer = substr($this->buffer, $bodyStart + $length);

            /** @var array<array-key, mixed>|scalar|null $message */
            $message = json_decode($body, true);
            if (\is_array($message)) {
                $messages[] = $message;
            }
        }
    }
}
