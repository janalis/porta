<?php

declare(strict_types=1);

/**
 * The Content-Length framing preamble shared by the stub LSP servers and DAP
 * adapters (`require`d by each; they stay standalone-executable scripts).
 * {@see sendMessage()} writes one framed message; {@see readFrames()} yields
 * each decoded inbound frame until stdin EOF, so a stub keeps its dispatch
 * inline in a plain foreach. Stubs whose *deviant* I/O is the point — the
 * slow-reading server's byte-at-a-time reads, the chunked server's split
 * writes, the garbage server's corrupt header — keep that side hand-rolled.
 *
 * @param resource $out
 */
function sendMessage($out, array $message): void
{
    $json = json_encode($message, JSON_UNESCAPED_SLASHES);
    fwrite($out, 'Content-Length: '.strlen($json)."\r\n\r\n".$json);
    fflush($out);
}

/**
 * @param resource $in
 *
 * @return Generator<array> decoded frames, until EOF
 */
function readFrames($in): Generator
{
    $buffer = '';
    while (!feof($in)) {
        $chunk = fread($in, length: 8192);
        if (false === $chunk) {
            break;
        }
        $buffer .= $chunk;

        while (true) {
            $headerEnd = strpos($buffer, needle: "\r\n\r\n");
            if (false === $headerEnd || !preg_match('/Content-Length:\s*(\d+)/i', substr($buffer, offset: 0, length: $headerEnd), $m)) {
                break;
            }
            $length = (int) $m[1];
            $bodyStart = $headerEnd + 4;
            if (strlen($buffer) < ($bodyStart + $length)) {
                break;
            }
            $message = json_decode(substr($buffer, $bodyStart, $length), associative: true);
            $buffer = substr($buffer, $bodyStart + $length);
            if (is_array($message)) {
                yield $message;
            }
        }
    }
}
